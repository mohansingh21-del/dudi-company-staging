<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use App\Models\ShiftPlan;
use App\Models\ShiftWorkforceDeployment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RemoveExpiredBorrowedEmployeesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'borrowed:cleanup {--dry-run : Show what would be removed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-remove borrowed employees whose borrowed shift AND home shift times have both completed';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $now    = Carbon::now();

        $this->info('[' . $now->toDateTimeString() . '] Starting borrowed employee cleanup...');

        // Fetch all shift plans that have active borrowed deployments,
        // along with their shift timing information.
        $shiftPlans = ShiftPlan::whereHas('workforceDeployments', function ($query) {
            $query->active()->borrowed();
        })
            ->with('shift')
            ->get();

        if ($shiftPlans->isEmpty()) {
            $this->info('No shift plans with active borrowed employees found. Nothing to do.');
            return 0;
        }

        $totalRemoved = 0;

        DB::transaction(function () use ($shiftPlans, $now, $dryRun, &$totalRemoved) {
            foreach ($shiftPlans as $shiftPlan) {
                $shift = $shiftPlan->shift;

                if (!$shift) {
                    $this->warn("Shift Plan #{$shiftPlan->id} has no associated shift. Skipping.");
                    continue;
                }

                // Build the borrowed shift end datetime
                $borrowedShiftEnd = $this->buildShiftEndDatetime($shiftPlan->planning_date, $shift);

                // Only process if the borrowed shift has ended
                if ($now->lt($borrowedShiftEnd)) {
                    continue;
                }

                // Find active borrowed deployments for this shift plan
                $borrowedDeployments = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlan->id)
                    ->active()
                    ->borrowed()
                    ->with('employee')
                    ->get();

                if ($borrowedDeployments->isEmpty()) {
                    continue;
                }

                $planningDate = Carbon::parse($shiftPlan->planning_date)->format('Y-m-d');

                foreach ($borrowedDeployments as $deployment) {
                    $employeeName = $deployment->employee
                        ? $deployment->employee->name
                        : 'Unknown';

                    // Resolve the employee's home shift end datetime
                    $homeShiftEnd = $this->resolveHomeShiftEnd($deployment, $planningDate);

                    // Use the later of borrowed shift end and home shift end
                    $effectiveEnd = $borrowedShiftEnd->copy();
                    if ($homeShiftEnd && $homeShiftEnd->gt($effectiveEnd)) {
                        $effectiveEnd = $homeShiftEnd;
                    }

                    // Only remove if NOW is past the effective (later) end time
                    if ($now->lt($effectiveEnd)) {
                        if ($dryRun) {
                            $this->line("[DRY-RUN] Skipping: {$employeeName} (Deployment #{$deployment->id}) — home shift ends at {$effectiveEnd->toDateTimeString()}");
                        }
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("[DRY-RUN] Would remove: {$employeeName} (Deployment #{$deployment->id}) from Shift Plan #{$shiftPlan->id} ({$shift->shift_name})");
                    } else {
                        $deployment->update([
                            'status'         => 'removed',
                            'removed_reason' => 'Shift time completed (auto-removed)',
                        ]);

                        $this->line("Removed: {$employeeName} (Deployment #{$deployment->id}) from Shift Plan #{$shiftPlan->id} ({$shift->shift_name})");
                    }

                    $totalRemoved++;
                }
            }
        });

        $actionWord = $dryRun ? 'would be removed' : 'removed';
        $this->info("Cleanup complete. {$totalRemoved} borrowed employee(s) {$actionWord}.");

        if ($totalRemoved > 0 && !$dryRun) {
            Log::info("Borrowed employee cleanup: {$totalRemoved} employee(s) auto-removed after shift completion.");
        }

        return 0;
    }

    /**
     * Build the absolute end datetime for a shift on a given planning date.
     * Handles cross-midnight (night) shifts by adding a day when end_time <= start_time.
     *
     * @param  string|\Carbon\Carbon  $planningDate
     * @param  \App\Models\Shift      $shift
     * @return \Carbon\Carbon
     */
    private function buildShiftEndDatetime($planningDate, Shift $shift)
    {
        $date = Carbon::parse($planningDate);
        $shiftStartTime = Carbon::parse($shift->start_time);
        $shiftEndTime   = Carbon::parse($shift->end_time);

        $endDatetime = $date->copy()->setTimeFrom($shiftEndTime);

        // Night shift: end_time <= start_time means shift ends the next day
        if ($shiftEndTime->format('H:i:s') <= $shiftStartTime->format('H:i:s')) {
            $endDatetime->addDay();
        }

        return $endDatetime;
    }

    /**
     * Resolve the employee's home shift end datetime on the planning date.
     * Looks up the employee's active shift assignment to find their home shift timing.
     *
     * @param  \App\Models\ShiftWorkforceDeployment  $deployment
     * @param  string                                 $planningDate  (Y-m-d)
     * @return \Carbon\Carbon|null
     */
    private function resolveHomeShiftEnd(ShiftWorkforceDeployment $deployment, $planningDate)
    {
        if (!$deployment->employee) {
            return null;
        }

        // Find the employee's active shift assignment on this planning date
        $homeAssignment = EmployeeShiftAssignment::where('employee_id', $deployment->employee_id)
            ->where('from_date', '<=', $planningDate)
            ->where(function ($q) use ($planningDate) {
                $q->whereNull('to_date')
                    ->orWhere('to_date', '>=', $planningDate);
            })
            ->first();

        if (!$homeAssignment) {
            return null;
        }

        // Get the home shift record
        $homeShift = Shift::find($homeAssignment->shift_id);

        if (!$homeShift) {
            return null;
        }

        return $this->buildShiftEndDatetime($planningDate, $homeShift);
    }
}
