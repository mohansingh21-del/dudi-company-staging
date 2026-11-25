<?php

namespace App\Services;

use App\Models\AttendanceProcessed;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\ShiftPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps a Leave row behind every attendance day that is marked 'leave' or
 * 'rest_day'.
 *
 * This is the mirror of LeaveController::syncAttendanceForLeave, which runs the
 * other way (an approved leave gets a same-day attendance row). Together they
 * mean the Form E register, the leave balance and payroll all read one source
 * no matter which screen the day was entered on:
 *
 *   - 'rest_day'  -> an approved Compensatory Rest leave for that day
 *   - 'leave'     -> an approved leave for that day, on the block the caller named
 *
 * Rows this class creates carry source = 'attendance'. Only those are removed
 * again when the day moves off leave/rest_day; a leave filed by hand in Leave
 * Management (source = 'manual') is never touched here.
 */
class AttendanceLeaveSync
{
    /** Attendance statuses that must be backed by a Leave row. */
    public const LEAVE_STATUSES = ['leave', 'rest_day'];

    /** What an attendance row's punch columns are reset to when the day becomes leave. */
    public const CLEARED_PUNCHES = [
        'check_in' => null,
        'check_out' => null,
        'working_hours' => 0.00,
        'late_minutes' => 0,
        'early_exit_minutes' => 0,
    ];

    /** Attendance statuses that mean the employee actually worked the day. */
    public const WORKED_STATUSES = ['present', 'half_day'];

    /**
     * Fold a (status, leaveTypeId) pair to its canonical form before anything
     * else looks at it.
     *
     * A day sent as 'leave' whose leave type is the Compensatory Rest block is
     * really a rest day: it is stored as 'rest_day', it draws on the monthly
     * rest-day cap rather than an annual leave quota, and it needs no leave type
     * of its own. Accepting it spelled either way means a bulk sheet or an API
     * caller does not have to know which of the two spellings the system prefers.
     *
     * @return array{0: string, 1: int|null}  [status, leaveTypeId]
     */
    public static function normalize(string $attendanceStatus, ?int $leaveTypeId): array
    {
        if ($attendanceStatus === 'leave' && $leaveTypeId) {
            $type = LeaveType::find($leaveTypeId);

            if ($type && $type->register_group === 'compensatory_rest') {
                return ['rest_day', null];
            }
        }

        return [$attendanceStatus, $leaveTypeId];
    }

    /**
     * The LeaveType an attendance status maps onto.
     *
     * 'rest_day' always resolves to the single Compensatory Rest block and any
     * id passed in is ignored. 'leave' needs an explicit block that is on the
     * register and is not Compensatory Rest (that one has its own status).
     *
     * @throws \InvalidArgumentException  message is user-facing
     */
    public static function resolveLeaveType(string $attendanceStatus, ?int $leaveTypeId): LeaveType
    {
        if ($attendanceStatus === 'rest_day') {
            $type = LeaveType::where('register_group', 'compensatory_rest')->first();

            if (! $type) {
                throw new \InvalidArgumentException(
                    'The Compensatory Rest block is missing from the leave master, so a rest day cannot be recorded.'
                );
            }

            return $type;
        }

        if (! $leaveTypeId) {
            throw new \InvalidArgumentException('Leave type is required when marking a day as Leave.');
        }

        $type = LeaveType::find($leaveTypeId);

        if (! $type) {
            throw new \InvalidArgumentException('Selected leave type does not exist.');
        }

        if ($type->register_group === 'compensatory_rest') {
            throw new \InvalidArgumentException('Use the Rest Day status for Compensatory Rest, not Leave.');
        }

        return $type;
    }

    /**
     * Why this day cannot be recorded as leave/rest_day for this employee, or
     * null when it is allowed. Same gates the Leave apply form uses: a configured
     * quota, not present-and-deployed on a shift, no clashing leave on another block, the Compensatory Rest monthly
     * cap and the annual entitlement.
     *
     * A leave that already covers the day on the same block is excluded from the
     * counts, so re-saving an unchanged day is never refused.
     */
    public static function blockMessage(int $employeeId, string $date, LeaveType $type): ?string
    {
        $day = Carbon::parse($date)->format('Y-m-d');

        if (! $type->canApply()) {
            return $type->quotaMissingMessage();
        }

        $deployedMessage = static::deployedPresentMessage($employeeId, $day, $day);

        if ($deployedMessage) {
            return $deployedMessage;
        }

        $sameBlock = static::leaveOnBlock($employeeId, $day, $type->id);
        $excludeLeaveId = $sameBlock ? $sameBlock->id : null;

        $clash = Leave::where('employee_id', $employeeId)
            ->where('status', '!=', 'rejected')
            ->where('leave_type_id', '!=', $type->id)
            ->whereDate('from_date', '<=', $day)
            ->whereDate('to_date', '>=', $day)
            ->exists();

        if ($clash) {
            return "Another leave already covers {$day} for this employee. "
                . 'Cancel it in Leave Management before recording a different one from attendance.';
        }

        if ($type->register_group === 'compensatory_rest') {
            $capMessage = LeaveBalanceService::compRestLeaveCapMessage($employeeId, $day, $day, $excludeLeaveId);

            if ($capMessage) {
                return $capMessage;
            }
        }

        $quotaMessage = LeaveBalanceService::annualQuotaMessage($type, $employeeId, $day, $day, $excludeLeaveId);

        if ($quotaMessage) {
            return $quotaMessage;
        }

        return null;
    }

    /**
     * Why a leave over [$from, $to] must be refused because the employee worked:
     * a day in the range is marked present/half_day AND the employee is deployed
     * in a shift plan's workforce for that day. Null when no such day exists.
     *
     * Present alone is not enough — an undeployed present day is simply turned
     * into leave. Once the employee is on a shift's workforce the day feeds that
     * shift's manpower and closure figures, so it has to be undone there first
     * (remove the deployment) before a leave can cover it.
     */
    public static function deployedPresentMessage(int $employeeId, string $from, string $to): ?string
    {
        $from = Carbon::parse($from)->format('Y-m-d');
        $to = Carbon::parse($to)->format('Y-m-d');

        $presentDays = AttendanceProcessed::where('employee_id', $employeeId)
            ->whereIn('attendance_status', static::WORKED_STATUSES)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->all();

        if (empty($presentDays)) {
            return null;
        }

        $plan = ShiftPlan::with('shift:id,shift_name')
            ->whereIn('planning_date', $presentDays)
            ->whereHas('workforceDeployments', function ($q) use ($employeeId) {
                $q->where('employee_id', $employeeId)->where('status', 'active');
            })
            ->orderBy('planning_date')
            ->first();

        if (! $plan) {
            return null;
        }

        $day = Carbon::parse($plan->planning_date)->format('d-m-Y');
        $shift = optional($plan->shift)->shift_name;

        return "Leave cannot be applied for {$day}: the employee is marked present and is deployed in "
            . ($shift ? "the {$shift} shift" : 'a shift') . ' workforce for that day. '
            . 'Remove them from the shift workforce first.';
    }

    /**
     * Ensure an approved Leave exists for this day on the resolved block. Call
     * this only after blockMessage() has returned null.
     *
     * If a leave already covers the day on that block it is left in place (an
     * attendance-sourced one is nudged to 'approved' if it was still pending); a
     * stale attendance-sourced leave on a different block — the leave type on the
     * attendance row was changed — is dropped and replaced.
     */
    public static function sync(int $employeeId, string $date, string $attendanceStatus, ?int $leaveTypeId): void
    {
        $type = static::resolveLeaveType($attendanceStatus, $leaveTypeId);
        $day = Carbon::parse($date)->format('Y-m-d');

        $onBlock = static::leaveOnBlock($employeeId, $day, $type->id);

        if ($onBlock) {
            if ($onBlock->source === 'attendance' && $onBlock->status !== 'approved') {
                $onBlock->update(['status' => 'approved', 'approved_by' => Auth::id()]);
            }

            static::dropStaleSynced($employeeId, $day, $type->id);

            return;
        }

        static::dropStaleSynced($employeeId, $day, $type->id);

        Leave::create([
            'employee_id' => $employeeId,
            'leave_type_id' => $type->id,
            'from_date' => $day,
            'to_date' => $day,
            'reason' => 'Recorded from attendance',
            'status' => 'approved',
            'source' => 'attendance',
            'approved_by' => Auth::id(),
        ]);
    }

    /**
     * Remove the attendance-sourced Leave rows for this day. Used when the day
     * moves to present/absent/half_day. Manual leaves are left alone.
     */
    public static function clear(int $employeeId, string $date): void
    {
        $day = Carbon::parse($date)->format('Y-m-d');

        Leave::where('employee_id', $employeeId)
            ->where('source', 'attendance')
            ->whereDate('from_date', $day)
            ->whereDate('to_date', $day)
            ->delete();
    }

    /**
     * Take this day out of every leave that covers it, whatever its source.
     * Used when the day is marked present/half_day: the employee worked, so the
     * leave for that day is cancelled and stops drawing on the balance.
     *
     * A single-day leave is cancelled outright (an attendance-sourced one is
     * deleted, a hand-filed one is kept as 'rejected' with a note so Leave
     * Management still shows what happened). A multi-day leave only loses the
     * one day: it is shortened, or split in two when the day falls mid-range.
     */
    public static function releaseDay(int $employeeId, string $date): void
    {
        $day = Carbon::parse($date)->format('Y-m-d');
        $prev = Carbon::parse($day)->subDay()->format('Y-m-d');
        $next = Carbon::parse($day)->addDay()->format('Y-m-d');

        $leaves = Leave::where('employee_id', $employeeId)
            ->where('status', '!=', 'rejected')
            ->whereDate('from_date', '<=', $day)
            ->whereDate('to_date', '>=', $day)
            ->get();

        foreach ($leaves as $leave) {
            $from = Carbon::parse($leave->from_date)->format('Y-m-d');
            $to = Carbon::parse($leave->to_date)->format('Y-m-d');

            if ($from === $day && $to === $day) {
                if ($leave->source === 'attendance') {
                    $leave->delete();
                } else {
                    $leave->update([
                        'status' => 'rejected',
                        'reason' => trim(($leave->reason ?? '') . " [Cancelled: attendance marked present on {$day}]"),
                    ]);
                }

                continue;
            }

            if ($from === $day) {
                $leave->update(['from_date' => $next]);
            } elseif ($to === $day) {
                $leave->update(['to_date' => $prev]);
            } else {
                $tail = $leave->replicate();
                $tail->from_date = $next;
                $tail->save();

                $leave->update(['to_date' => $prev]);
            }
        }
    }

    /**
     * Bring Leave Management in step with a day that is no longer leave/rest_day.
     * A worked day cancels whatever leave covered it; any other status only
     * removes the rows attendance itself created.
     */
    public static function dayLeftLeave(int $employeeId, string $date, string $attendanceStatus): void
    {
        if (in_array($attendanceStatus, static::WORKED_STATUSES, true)) {
            static::releaseDay($employeeId, $date);

            return;
        }

        static::clear($employeeId, $date);
    }

    /**
     * A manual (hand-filed) leave covering this day, if any. The attendance
     * screens refuse to overwrite one of these with 'absent'; a worked status
     * cancels it instead (see releaseDay).
     */
    public static function manualLeaveOn(int $employeeId, string $date): ?Leave
    {
        return Leave::where('employee_id', $employeeId)
            ->where('source', '!=', 'attendance')
            ->where('status', '!=', 'rejected')
            ->whereDate('from_date', '<=', Carbon::parse($date)->format('Y-m-d'))
            ->whereDate('to_date', '>=', Carbon::parse($date)->format('Y-m-d'))
            ->first();
    }

    /**
     * The leave type backing one attendance day, for read endpoints.
     * `['leave_type_id' => int|null, 'leave_type_name' => string|null]`.
     *
     * Prefers a same-day attendance-sourced row (what `sync()` writes), then
     * falls back to any non-rejected leave whose range covers the day — so a
     * multi-day manual leave still shows its type on each attendance row.
     */
    public static function leaveTypeForDay(int $employeeId, string $date): array
    {
        $day = Carbon::parse($date)->format('Y-m-d');

        $leave = Leave::with('leaveType:id,name')
            ->where('employee_id', $employeeId)
            ->where('status', '!=', 'rejected')
            ->whereDate('from_date', '<=', $day)
            ->whereDate('to_date', '>=', $day)
            ->orderByRaw("source = 'attendance' desc")
            ->orderByRaw('DATEDIFF(to_date, from_date) asc')
            ->first();

        return [
            'leave_type_id' => $leave ? $leave->leave_type_id : null,
            'leave_type_name' => $leave ? optional($leave->leaveType)->name : null,
        ];
    }

    /**
     * Same as leaveTypeForDay() but batched for a list of attendance rows.
     * Returns `[attendance_id => ['leave_type_id' => ..., 'leave_type_name' => ...]]`.
     *
     * @param  iterable  $attendances  AttendanceProcessed models (need id, employee_id, date)
     */
    public static function leaveTypeMap($attendances): array
    {
        $attendances = collect($attendances);
        $map = [];

        if ($attendances->isEmpty()) {
            return $map;
        }

        $days = $attendances->map(fn ($a) => Carbon::parse($a->date)->format('Y-m-d'));

        $leaves = Leave::with('leaveType:id,name')
            ->whereIn('employee_id', $attendances->pluck('employee_id')->unique()->all())
            ->where('status', '!=', 'rejected')
            ->whereDate('from_date', '<=', $days->max())
            ->whereDate('to_date', '>=', $days->min())
            ->get(['id', 'employee_id', 'from_date', 'to_date', 'leave_type_id', 'source'])
            // Attendance-sourced first, then shortest range — the closest match
            // to the day wins when several leaves overlap it.
            ->sortByDesc(fn ($lv) => $lv->source === 'attendance')
            ->values();

        foreach ($attendances as $att) {
            $day = Carbon::parse($att->date)->format('Y-m-d');

            $match = $leaves->first(function ($lv) use ($att, $day) {
                return $lv->employee_id == $att->employee_id
                    && $day >= Carbon::parse($lv->from_date)->format('Y-m-d')
                    && $day <= Carbon::parse($lv->to_date)->format('Y-m-d');
            });

            $map[$att->id] = [
                'leave_type_id' => $match ? $match->leave_type_id : null,
                'leave_type_name' => $match ? optional($match->leaveType)->name : null,
            ];
        }

        return $map;
    }

    /** Any non-rejected leave covering $day on this block, whatever its source. */
    protected static function leaveOnBlock(int $employeeId, string $day, int $leaveTypeId): ?Leave
    {
        return Leave::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', '!=', 'rejected')
            ->whereDate('from_date', '<=', $day)
            ->whereDate('to_date', '>=', $day)
            ->first();
    }

    /**
     * Delete single-day attendance-sourced leaves on $day that are NOT on the
     * given block — left over from an earlier leave type on the same attendance
     * row.
     */
    protected static function dropStaleSynced(int $employeeId, string $day, int $keepLeaveTypeId): void
    {
        Leave::where('employee_id', $employeeId)
            ->where('source', 'attendance')
            ->where('leave_type_id', '!=', $keepLeaveTypeId)
            ->whereDate('from_date', $day)
            ->whereDate('to_date', $day)
            ->delete();
    }
}
