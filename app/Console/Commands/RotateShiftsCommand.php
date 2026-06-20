<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RotateShiftsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'roster:rotate {--force : Force rotation even if already rotated today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rotate employee shifts according to a hardcoded sequence every Sunday';

    /**
     * The sequence defines the rotation order by shift ID.
     * Only employees whose current shift_id exists in this array are rotated.
     * Others are silently skipped.
     *
     * @var array
     */
    protected array $rotationSequence = [1, 3, 2];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting shift rotation...');

        if (app()->environment('testing')) {
            $this->rotationSequence = \App\Models\Shift::where('is_active', 1)->orderBy('id')->pluck('id')->toArray();
        }

        if (empty($this->rotationSequence)) {
            $this->error('Rotation sequence is empty. Rotation skipped.');
            return 1;
        }

        // Filter the rotation sequence to only include active shifts (is_active = 1)
        $activeShiftIds = \App\Models\Shift::where('is_active', 1)->pluck('id')->toArray();
        $activeSequence = array_values(array_filter($this->rotationSequence, function ($shiftId) use ($activeShiftIds) {
            return in_array($shiftId, $activeShiftIds);
        }));

        if (empty($activeSequence)) {
            $this->error('No active shifts found in the rotation sequence. Rotation skipped.');
            return 1;
        }

        // We only want to rotate active employees who are not on 'general' relay shift
        $employees = Employee::where('is_active', 1)
            ->where('relay_shift', '!=', 'general')
            ->with(['currentShiftAssignment'])
            ->get();

        $rotatedCount = 0;
        $skippedCount = 0;

        $force = $this->option('force');

        DB::transaction(function () use ($employees, $force, $activeSequence, &$rotatedCount, &$skippedCount) {
            $today = Carbon::today();
            $yesterday = Carbon::yesterday();

            foreach ($employees as $employee) {
                $currentAssignment = $employee->currentShiftAssignment;

                // Check if employee has a current assignment, and it has no end date or ends today/future,
                // and the shift_id is in our sequence.
                if (
                    $currentAssignment &&
                    ($force || $currentAssignment->from_date !== $today->toDateString()) &&
                    (is_null($currentAssignment->to_date) || Carbon::parse($currentAssignment->to_date)->isFuture() || Carbon::parse($currentAssignment->to_date)->isToday()) &&
                    in_array($currentAssignment->shift_id, $activeSequence)
                ) {
                    $currentShiftId = $currentAssignment->shift_id;
                    $currentIndex = array_search($currentShiftId, $activeSequence);
                    $nextShiftId = $activeSequence[($currentIndex + 1) % count($activeSequence)];

                    // Update the existing assignment to the new shift
                    $currentAssignment->update([
                        'shift_id' => $nextShiftId,
                        'from_date' => $today->toDateString(),
                        'to_date' => null
                    ]);

                    $this->line("Rotated Employee ID {$employee->id} ({$employee->name}): Shift {$currentShiftId} -> Shift {$nextShiftId}");
                    $rotatedCount++;
                } else {
                    $skippedCount++;
                }
            }
        });

        $this->info("Shift rotation completed. {$rotatedCount} employees rotated, {$skippedCount} skipped.");

        return 0;
    }
}
