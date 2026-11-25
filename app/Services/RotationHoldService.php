<?php

namespace App\Services;

use App\Console\Commands\RotateShiftsCommand;
use App\Models\EmployeeShiftOverride;
use App\Models\RelayShiftMapping;
use App\Models\ShiftWorkforceDeployment;
use Carbon\Carbon;

/**
 * Keeps the rotation hold true between runs of roster:rotate.
 *
 * The command only looks at who is deployed on an open shift plan at the moment
 * it runs. It writes next week's relay mappings ahead of the week, so anyone
 * deployed after the run and before the week starts would rotate off the shift
 * of the plan they are standing on. The hold is the same override the command
 * writes, so the next run expires and re-evaluates it like any other.
 */
class RotationHoldService
{
    /**
     * Hold a freshly deployed employee on their shift for a week that has
     * already been rotated but has not started yet.
     *
     * @param  ShiftWorkforceDeployment  $deployment
     * @return void
     */
    public function holdDeployment(ShiftWorkforceDeployment $deployment)
    {
        $employee = $deployment->employee;
        $plan = $deployment->shiftPlan;

        if (!$employee || !$plan || !$employee->is_active || !$employee->relay_id) {
            return;
        }

        $relay = $employee->relay;
        if (!$relay || !$relay->is_rotating || !$relay->is_active) {
            return;
        }

        // Nothing rotated ahead of today: the next run will see this deployment.
        $upcoming = RelayShiftMapping::where('relay_id', $relay->id)
            ->whereDate('week_start_date', '>', Carbon::today()->toDateString())
            ->orderBy('week_start_date')
            ->first();
        if (!$upcoming) {
            return;
        }

        $weekStart = Carbon::parse($upcoming->week_start_date)->toDateString();

        // A borrowed employee works another relay's plan, so keep their own
        // shift as it resolved on that date instead.
        $shiftId = $deployment->is_borrowed
            ? $employee->getShiftIdForDate($plan->planning_date->toDateString())
            : $plan->shift_id;
        if (!$shiftId) {
            return;
        }

        // A deliberate override already planned for the new week wins.
        $planned = EmployeeShiftOverride::where('employee_id', $employee->id)
            ->where('effective_from', '>=', $weekStart)
            ->where(function ($q) {
                $q->whereNull('reason')->orWhere('reason', '!=', RotateShiftsCommand::HOLD_REASON);
            })
            ->exists();
        if ($planned) {
            return;
        }

        EmployeeShiftOverride::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'effective_from' => $weekStart,
                'reason' => RotateShiftsCommand::HOLD_REASON,
            ],
            [
                'effective_until' => null,
                'shift_id' => $shiftId,
            ]
        );
    }

    /**
     * Drop holds that have not started yet for employees no longer standing on
     * any open shift plan.
     *
     * A hold already in effect is left alone: the employee stays on the held
     * shift for the rest of that week and rejoins their relay on the next run.
     *
     * @param  array  $employeeIds
     * @return void
     */
    public function releaseUnstartedHolds(array $employeeIds)
    {
        if (empty($employeeIds)) {
            return;
        }

        $stillDeployedIds = ShiftWorkforceDeployment::active()
            ->whereIn('employee_id', $employeeIds)
            ->whereHas('shiftPlan', function ($q) {
                $q->notClosed();
            })
            ->pluck('employee_id')
            ->all();

        EmployeeShiftOverride::where('reason', RotateShiftsCommand::HOLD_REASON)
            ->whereIn('employee_id', array_diff($employeeIds, $stillDeployedIds))
            ->whereDate('effective_from', '>', Carbon::today()->toDateString())
            ->delete();
    }
}
