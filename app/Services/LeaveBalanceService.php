<?php

namespace App\Services;

use App\Models\AttendanceProcessed;
use App\Models\Employee;
use App\Models\LeaveRegisterReport;
use App\Models\LeaveRegisterReportRow;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computes the Form E leave ledger. Nothing here is stored.
 *
 *   Opening Balance  = always 0 — leave is not carried forward, every year
 *                      starts fresh and any unused balance lapses
 *   Added            = the block's annual quota from the leave_types master —
 *                      every block including Compensatory Rest, whose quota the
 *                      admin sets like any other
 *   Rest Not Allowed = weeks the employee worked 6+ days with no rest day.
 *                      Reported for column 6 only; it no longer feeds Added.
 *   Availed          = approved rows in `leaves` for that block, clipped to the year
 *   Closing Balance  = Added - Availed, floored at 0
 *
 * Because nothing carries forward, each year is independent: a year is computed
 * from three grouped queries against that year alone, with no walk over history.
 */
class LeaveBalanceService
{
    /**
     * Fallback monthly paid-rest-day cap, used only when the Compensatory Rest
     * block is missing from the leave master or carries no quota.
     *
     * Replaces the old per-employee employee_payrolls.rest_days setting, which
     * was reached through an Employee::rest_days accessor that no longer exists.
     * One establishment-wide number so payroll cannot vary person to person.
     */
    const MONTHLY_PAID_REST_DAYS = 4;

    /** Memoised cap, so a per-employee listing does not re-query per row. */
    protected static $monthlyPaidRestDays;

    /**
     * The monthly paid-rest-day cap, taken from the Compensatory Rest quota on
     * the leave master rather than a constant, so an admin editing the annual
     * quota moves payroll and attendance together.
     *
     * The stored quota is annual (48 days = 4 a month). It is floored, never
     * rounded up: a cap that rounded up would pay out more rest days across the
     * year than the quota actually grants. A positive quota below 12 cannot be
     * expressed as a whole monthly cap at all, and is treated as one a month
     * rather than flooring to zero — a zero cap would quietly stop paying rest
     * days entirely, which is both the worse error and hard to tell from a
     * month where none were marked.
     *
     * Note this is a fresh cap each month, not a yearly budget: a worker with
     * rest days in every month can be paid up to the monthly cap x 12.
     */
    public static function monthlyPaidRestDays(): int
    {
        if (static::$monthlyPaidRestDays !== null) {
            return static::$monthlyPaidRestDays;
        }

        try {
            $annualQuota = (int) LeaveType::where('register_group', 'compensatory_rest')
                ->value('allowed_days');
        } catch (\Throwable $e) {
            // Master unreadable (migrations not run yet, for instance) — fall
            // back rather than break the payroll listing.
            $annualQuota = 0;
        }

        return static::$monthlyPaidRestDays = $annualQuota > 0
            ? max(1, intdiv($annualQuota, 12))
            : self::MONTHLY_PAID_REST_DAYS;
    }

    /** Drop the memoised cap. Called after the leave master is edited. */
    public static function forgetMonthlyPaidRestDays(): void
    {
        static::$monthlyPaidRestDays = null;
    }

    /**
     * Rest days already marked in attendance in the calendar month of $date.
     *
     * @param  array  $excludeAttendanceIds  The row being edited, so re-saving a
     *                                       day that is already a rest day does
     *                                       not count itself as a new one.
     */
    public static function attendanceRestDaysInMonth(int $employeeId, string $date, array $excludeAttendanceIds = []): int
    {
        $month = Carbon::parse($date);

        $query = DB::table((new AttendanceProcessed)->getTable())
            ->where('employee_id', $employeeId)
            ->where('attendance_status', 'rest_day')
            ->whereBetween('date', [
                $month->copy()->startOfMonth()->format('Y-m-d'),
                $month->copy()->endOfMonth()->format('Y-m-d'),
            ]);

        $excludeAttendanceIds = array_filter($excludeAttendanceIds);

        if ($excludeAttendanceIds) {
            $query->whereNotIn('id', $excludeAttendanceIds);
        }

        return (int) $query->count();
    }

    /**
     * Compensatory Rest leave days booked in the calendar month of $date.
     *
     * Rejected leaves do not count, but pending ones do — otherwise a stack of
     * pending applications could all be approved past the cap one by one.
     *
     * A leave spanning a month boundary is clipped to the month asked for, the
     * same way availedMap() clips to the year.
     */
    public static function compRestLeaveDaysInMonth(int $employeeId, string $date, ?int $excludeLeaveId = null): int
    {
        $typeId = LeaveType::where('register_group', 'compensatory_rest')->value('id');

        if (! $typeId) {
            return 0;
        }

        $month = Carbon::parse($date);
        $start = $month->copy()->startOfMonth()->format('Y-m-d');
        $end = $month->copy()->endOfMonth()->format('Y-m-d');

        $query = DB::table('leaves')
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $typeId)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $end)
            ->whereDate('to_date', '>=', $start);

        if ($excludeLeaveId) {
            $query->where('id', '!=', $excludeLeaveId);
        }

        return (int) $query->sum(
            DB::raw("DATEDIFF(LEAST(to_date, '{$end}'), GREATEST(from_date, '{$start}')) + 1")
        );
    }

    /**
     * The month's rest days, counted across both places they can be recorded.
     *
     * Attendance rest days and Compensatory Rest leave draw on one budget: a
     * month gives the employee so many rest days, whether taken as the weekly
     * rest itself or granted afterwards as compensatory rest. Counting them
     * separately would let either screen be used to get past the other's cap.
     */
    public static function restDaysUsedInMonth(
        int $employeeId,
        string $date,
        array $excludeAttendanceIds = [],
        ?int $excludeLeaveId = null
    ): int {
        return static::attendanceRestDaysInMonth($employeeId, $date, $excludeAttendanceIds)
            + static::compRestLeaveDaysInMonth($employeeId, $date, $excludeLeaveId);
    }

    /**
     * Split a date range into how many of its days fall in each calendar month.
     *
     * @return array  ['YYYY-MM' => int days]
     */
    public static function daysByMonth(string $fromDate, string $toDate): array
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->startOfDay();

        if ($to->lt($from)) {
            return [];
        }

        $months = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $monthEnd = $cursor->copy()->endOfMonth();
            $sliceEnd = $monthEnd->lt($to) ? $monthEnd : $to;

            $months[$cursor->format('Y-m')] = $cursor->diffInDays($sliceEnd) + 1;

            $cursor = $sliceEnd->copy()->addDay()->startOfDay();
        }

        return $months;
    }

    /**
     * Guard for applying Compensatory Rest leave.
     *
     * Checked month by month, so a leave straddling a month boundary is only
     * refused for the month that is actually full.
     *
     * Returns null when the leave may be filed, or the refusal message.
     */
    public static function compRestLeaveCapMessage(
        int $employeeId,
        string $fromDate,
        string $toDate,
        ?int $excludeLeaveId = null
    ): ?string {
        $cap = static::monthlyPaidRestDays();

        foreach (static::daysByMonth($fromDate, $toDate) as $month => $adding) {
            $anchor = $month . '-01';
            $used = static::restDaysUsedInMonth($employeeId, $anchor, [], $excludeLeaveId);

            if ($used + $adding <= $cap) {
                continue;
            }

            $label = Carbon::parse($anchor)->format('F Y');

            return "Compensatory Rest limit exceeded for {$label}: only {$cap} rest days are allowed per month, "
                . "{$used} are already booked, and this leave adds {$adding} more. "
                . 'The remaining days can only be taken in the following month.';
        }

        return null;
    }

    /**
     * Guard for marking a day as a rest day.
     *
     * A month gives at most monthlyPaidRestDays() rest days. Once they are used
     * up the next one has to wait for the following month, so attendance can
     * never record more rest days than the Compensatory Rest quota pays for.
     *
     * Returns null when the rest day may be marked, or the refusal message when
     * the month is exhausted.
     *
     * @param  int  $pendingInBatch  Rest days already accepted for this employee
     *                               and month earlier in the same request, which
     *                               are not in the database yet.
     */
    public static function restDayCapMessage(
        int $employeeId,
        string $date,
        array $excludeAttendanceIds = [],
        int $pendingInBatch = 0
    ): ?string {
        $cap = static::monthlyPaidRestDays();
        $used = static::restDaysUsedInMonth($employeeId, $date, $excludeAttendanceIds) + $pendingInBatch;

        if ($used < $cap) {
            return null;
        }

        $month = Carbon::parse($date)->format('F Y');

        return "Rest day limit reached for {$month}: {$used} of {$cap} allowed rest days are already marked. "
            . 'The next rest day can only be assigned in the following month.';
    }

    /**
     * Paid and unpaid leave days per employee for a payroll month.
     *
     * Counted as **distinct calendar dates**, not as a sum of leave lengths.
     * Two leaves covering the same day (a Medical leave and a Compensatory Rest
     * leave on 3 Aug, say) are one day off, not two, and summing them paid the
     * same day twice.
     *
     * A date the attendance register already pays for — present, half day or
     * rest day — is dropped: attendance is the day-level record, so a leave
     * filed over a day that was actually worked must not add a second paid day
     * on top of it. Dates marked absent, marked leave, or with no attendance row
     * at all still count, since none of those are paid through attendance.
     *
     * Where a paid and an unpaid leave land on the same date, paid wins — an
     * extra overlapping record should not cost the employee a day's pay.
     *
     * @param  array  $employeeIds
     * @return array  [employee_id => ['paid' => int, 'unpaid' => int]]
     */
    public static function monthlyLeaveDays(array $employeeIds, int $month, int $year): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $start = $monthStart->format('Y-m-d');
        $end = $monthEnd->format('Y-m-d');

        // Overlap, not "starts or ends in this month" — a leave running from
        // late July into September covers all of August and was being missed.
        $leaves = DB::table('leaves')
            ->join('leave_types', 'leaves.leave_type_id', '=', 'leave_types.id')
            ->where('leaves.status', 'approved')
            ->whereIn('leaves.employee_id', $employeeIds)
            ->whereDate('leaves.from_date', '<=', $end)
            ->whereDate('leaves.to_date', '>=', $start)
            ->get([
                'leaves.employee_id',
                'leaves.from_date',
                'leaves.to_date',
                'leave_types.register_group',
                'leave_types.leave_category',
            ]);

        // Days attendance already pays for, so a leave cannot pay them again.
        $paidByAttendance = [];

        $attendanceRows = DB::table((new AttendanceProcessed)->getTable())
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('attendance_status', ['present', 'half_day', 'rest_day'])
            ->whereBetween('date', [$start, $end])
            ->get(['employee_id', 'date']);

        foreach ($attendanceRows as $row) {
            $paidByAttendance[$row->employee_id][Carbon::parse($row->date)->format('Y-m-d')] = true;
        }

        // employee_id => [date => 'paid'|'unpaid']
        $dates = [];

        foreach ($leaves as $leave) {
            $category = $leave->register_group
                ? LeaveType::categoryForGroup($leave->register_group)
                : ((string) $leave->leave_category === 'paid' ? 'paid' : 'unpaid');

            $from = Carbon::parse($leave->from_date)->startOfDay()->max($monthStart);
            $to = Carbon::parse($leave->to_date)->startOfDay()->min($monthEnd);

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $key = $day->format('Y-m-d');

                if (isset($paidByAttendance[$leave->employee_id][$key])) {
                    continue;
                }

                if (($dates[$leave->employee_id][$key] ?? null) === 'paid') {
                    continue;
                }

                $dates[$leave->employee_id][$key] = $category;
            }
        }

        $summary = [];

        foreach ($employeeIds as $employeeId) {
            $summary[$employeeId] = ['paid' => 0, 'unpaid' => 0];
        }

        foreach ($dates as $employeeId => $byDate) {
            foreach ($byDate as $category) {
                $summary[$employeeId][$category]++;
            }
        }

        return $summary;
    }

    /**
     * Full ledger for a set of employees in one year.
     *
     * @param  \Illuminate\Support\Collection  $employees  Employee models
     * @return array  [employee_id => ['days_worked' => float, 'groups' => [group => [...]]]]
     */
    public function ledgerFor($employees, int $year): array
    {
        $employees = collect($employees);

        if ($employees->isEmpty()) {
            return [];
        }

        $employeeIds = $employees->pluck('id')->all();

        $availed = $this->availedMap($employeeIds, $year);
        $restDenied = $this->restNotAllowedMap($employeeIds, $year);
        $worked = $this->daysWorkedMap($employeeIds, $year);

        $leaveTypes = LeaveType::onRegister()->get()->keyBy('register_group');

        $ledger = [];

        foreach ($employees as $employee) {
            $groups = [];

            // Someone who had not joined yet, or who had already left, earns no
            // quota for that year. The register listing filters these people out
            // entirely, but a direct lookup by employee id can still ask for one.
            $employed = $this->employedDuring($employee, $year);

            foreach (LeaveType::REGISTER_GROUPS as $group => $label) {
                $leaveType = $leaveTypes->get($group);
                $typeId = $leaveType ? $leaveType->id : null;

                $restNotAllowed = (float) ($restDenied[$employee->id] ?? 0);

                // Every block, compensatory rest included, is credited from the
                // annual quota the admin sets on the master.
                $added = (float) ($leaveType->allowed_days ?? 0);

                if (! $employed) {
                    $added = 0.0;
                }

                $availedDays = $typeId
                    ? (float) ($availed[$employee->id][$typeId] ?? 0)
                    : 0.0;

                // Nothing is carried in from last year, so the opening balance
                // is always zero and anything unused simply lapses.
                $opening = 0.0;

                // A worker cannot carry a negative entitlement, so the register
                // floors the closing balance at zero.
                $closingBalance = max(0.0, round($opening + $added - $availedDays, 1));

                $groups[$group] = [
                    'register_group' => $group,
                    'label' => $label,
                    'leave_type_id' => $typeId,
                    'opening_balance' => $opening,
                    'added' => round($added, 1),
                    'availed' => round($availedDays, 1),
                    'closing_balance' => $closingBalance,
                    // Form E prints column 6 for Compensatory Rest only.
                    'rest_not_allowed' => $group === 'compensatory_rest' ? $restNotAllowed : null,
                ];
            }

            $ledger[$employee->id] = [
                'days_worked' => (float) ($worked[$employee->id] ?? 0),
                'groups' => $groups,
            ];
        }

        return $ledger;
    }

    /**
     * Approved leave days per employee, per leave type, for one year.
     *
     * A leave spanning a year boundary (28 Dec - 3 Jan) is clipped to the year
     * asked for, so the two years' registers add up to the leave's real length.
     *
     * @return array  [employee_id => [leave_type_id => float]]
     */
    public function availedMap(array $employeeIds, int $year): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $start = Carbon::create($year, 1, 1)->format('Y-m-d');
        $end = Carbon::create($year, 12, 31)->format('Y-m-d');

        $rows = DB::table('leaves')
            ->selectRaw(
                'employee_id, leave_type_id, SUM(DATEDIFF(LEAST(to_date, ?), GREATEST(from_date, ?)) + 1) AS days',
                [$end, $start]
            )
            ->where('status', 'approved')
            ->whereNotNull('leave_type_id')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('from_date', '<=', $end)
            ->whereDate('to_date', '>=', $start)
            ->groupBy('employee_id', 'leave_type_id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[$row->employee_id][$row->leave_type_id] = (float) $row->days;
        }

        return $map;
    }

    /**
     * Weekly rests denied — Form E column 6, and the source of compensatory rest.
     *
     * The Mines Act gives one rest day per week. A week where the employee has
     * no day marked `rest_day` and worked six or more days is a denied rest.
     * Weeks are grouped within the calendar year, so a week straddling 31 Dec
     * splits into two part-weeks that cannot reach the six-day threshold.
     *
     * @return array  [employee_id => int]
     */
    public function restNotAllowedMap(array $employeeIds, int $year): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $table = (new AttendanceProcessed)->getTable();

        $rows = DB::table($table)
            ->selectRaw('employee_id, YEARWEEK(date, 1) AS wk')
            ->selectRaw("SUM(CASE WHEN attendance_status = 'rest_day' THEN 1 ELSE 0 END) AS rest_days")
            ->selectRaw("SUM(CASE WHEN attendance_status IN ('present', 'half_day') THEN 1 ELSE 0 END) AS worked_days")
            ->whereIn('employee_id', $employeeIds)
            ->whereYear('date', $year)
            ->groupBy('employee_id', 'wk')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            if ((int) $row->rest_days === 0 && (int) $row->worked_days >= 6) {
                $map[$row->employee_id] = ($map[$row->employee_id] ?? 0) + 1;
            }
        }

        return $map;
    }

    /**
     * Days actually worked — Form E column 3. Half days count as half.
     *
     * @return array  [employee_id => float]
     */
    public function daysWorkedMap(array $employeeIds, int $year): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $table = (new AttendanceProcessed)->getTable();

        $rows = DB::table($table)
            ->selectRaw("employee_id, SUM(CASE WHEN attendance_status = 'present' THEN 1 WHEN attendance_status = 'half_day' THEN 0.5 ELSE 0 END) AS days")
            ->whereIn('employee_id', $employeeIds)
            ->whereYear('date', $year)
            ->groupBy('employee_id')
            ->get();

        return $rows->mapWithKeys(function ($row) {
            return [$row->employee_id => (float) $row->days];
        })->all();
    }

    /**
     * The canonical set of employees the register covers for a year.
     *
     * Shared by the live preview and by report generation so a generated
     * report cannot silently cover a different set of people than the screen
     * it was generated from.
     *
     * @param  array  $filters  site_id, department_id, designation_id, search,
     *                          employee_status ('active'|'all')
     */
    public function registerEmployeeQuery(int $year, array $filters = [])
    {
        $query = Employee::with(['department', 'designation', 'site'])
            ->orderBy('employee_code')
            ->orderBy('id');

        // Register rows are the establishment's own workers, matching the
        // attendance register's exclusions.
        $query->whereDoesntHave('roleUser.user.roles', function ($q) {
            $q->whereIn('name', ['Super Admin', 'CEO']);
        });

        foreach (['site_id', 'department_id', 'designation_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('surname', 'LIKE', "%{$search}%")
                    ->orWhere('employee_code', 'LIKE', "%{$search}%");
            });
        }

        // Anyone who had not joined yet, or who had already left, gets no row.
        $status = strtolower((string) ($filters['employee_status'] ?? 'active'));

        if ($status !== 'all') {
            $query->whereDate('joining_date', '<=', $year . '-12-31')
                ->where(function ($q) use ($year) {
                    $q->whereNull('date_of_exit')
                        ->orWhereDate('date_of_exit', '>=', $year . '-01-01');
                });
        }

        return $query;
    }

    /**
     * Was the employee on the rolls at any point during the year?
     *
     * An employee with no joining date on record is assumed to be, since the
     * alternative is silently zeroing a real worker's entitlement.
     */
    protected function employedDuring($employee, int $year): bool
    {
        if ($employee->joining_date && Carbon::parse($employee->joining_date)->year > $year) {
            return false;
        }

        if ($employee->date_of_exit && Carbon::parse($employee->date_of_exit)->year < $year) {
            return false;
        }

        return true;
    }

    /**
     * Freeze the year's register: compute every row once and store it.
     *
     * Covers the whole establishment — a statutory register is not filtered by
     * site or search.
     *
     * A year that already has a report is replaced in place: the same row is
     * reused so the list never shows duplicates, its old detail rows are
     * dropped, and `version` records how many times it has been redone. The
     * controller confirms with the caller before this is reached.
     */
    public function generateReport(int $year, ?int $userId = null, ?string $remarks = null): LeaveRegisterReport
    {
        $employees = $this->registerEmployeeQuery($year)->get();
        $ledger = $this->ledgerFor($employees, $year);

        // Quotas are not versioned, so record what produced these numbers.
        $leaveTypeSnapshot = LeaveType::onRegister()->get()
            ->map(function ($type) {
                return [
                    'register_group' => $type->register_group,
                    'name' => $type->name,
                    'leave_category' => $type->leave_category,
                    'allowed_days' => $type->allowed_days,
                    'is_active' => (bool) $type->is_active,
                ];
            })->values()->all();

        return DB::transaction(function () use ($year, $userId, $remarks, $employees, $ledger, $leaveTypeSnapshot) {
            $existing = LeaveRegisterReport::forYear($year);

            $attributes = [
                'year' => $year,
                'generated_by' => $userId,
                'generated_at' => now(),
                'employee_count' => $employees->count(),
                'leave_type_snapshot' => $leaveTypeSnapshot,
                'remarks' => $remarks,
            ];

            if ($existing) {
                // Reuse the row so the year keeps one stable id, and clear the
                // old detail rows rather than letting both generations coexist.
                $existing->rows()->delete();
                $existing->update($attributes + ['version' => $existing->version + 1]);
                $report = $existing;
            } else {
                $report = LeaveRegisterReport::create($attributes + ['version' => 1]);
            }

            $serial = 1;
            $now = now();
            $rows = [];

            foreach ($employees as $employee) {
                $entry = $ledger[$employee->id] ?? ['days_worked' => 0.0, 'groups' => []];

                $row = [
                    'report_id' => $report->id,
                    'employee_id' => $employee->id,
                    'serial_no' => $serial++,
                    'employee_code' => $employee->employee_code,
                    'employee_name' => trim($employee->name . ' ' . $employee->surname),
                    'days_worked' => $entry['days_worked'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                foreach ($entry['groups'] as $group => $figures) {
                    $prefix = LeaveRegisterReportRow::GROUP_PREFIX[$group];

                    $row[$prefix . '_opening'] = $figures['opening_balance'];
                    $row[$prefix . '_added'] = $figures['added'];
                    $row[$prefix . '_availed'] = $figures['availed'];
                    $row[$prefix . '_closing'] = $figures['closing_balance'];

                    if ($group === 'compensatory_rest') {
                        $row['comp_rest_not_allowed'] = $figures['rest_not_allowed'] ?? 0;
                    }
                }

                $rows[] = $row;
            }

            // Chunked so a large establishment does not build one oversized
            // insert statement.
            foreach (array_chunk($rows, 500) as $chunk) {
                LeaveRegisterReportRow::insert($chunk);
            }

            return $report->fresh();
        });
    }

    /**
     * Convenience wrapper for a single employee.
     */
    public function ledgerForEmployee(Employee $employee, int $year): array
    {
        $ledger = $this->ledgerFor(collect([$employee]), $year);

        return $ledger[$employee->id] ?? ['days_worked' => 0.0, 'groups' => []];
    }
}
