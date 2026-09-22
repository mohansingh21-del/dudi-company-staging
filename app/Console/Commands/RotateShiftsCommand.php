<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftOverride;
use App\Models\RelayShiftMapping;
use App\Models\Relay;
use App\Models\ShiftWorkforceDeployment;
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
    protected $description = 'Rotate relay shift mappings according to a hardcoded sequence every Sunday';

    /**
     * The sequence defines the rotation order by shift ID.
     */
    protected $sequence = [1, 3, 2]; // Shift ID 1 -> 3 -> 2 -> 1

    /**
     * Marks the overrides this command writes to hold an employee on their shift.
     */
    const HOLD_REASON = 'Rotation held: deployed on an open shift plan';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $force = $this->option('force');

        // Rotation sequence dynamically ordered by shift start time (descending / backwards)
        $activeSequence = \App\Models\Shift::where('is_active', 1)
            ->orderBy('start_time', 'desc')
            ->pluck('id')
            ->toArray();

        // Current week boundary (runs on Sunday/Monday, mapping is for the week starting today/yesterday)
        // Ensure weekStart is always the current week's Monday (or Sunday) to match scheduler
        $today = Carbon::today();
        if ($today->dayOfWeek === Carbon::SUNDAY) {
            $weekStart = $today->copy()->addDay()->toDateString();
        } else {
            $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        }
        $weekEnd = Carbon::parse($weekStart)->addDays(6)->toDateString();

        // Check if mappings for this week already exist (prevent duplicate runs)
        $existingThisWeek = RelayShiftMapping::where('week_start_date', $weekStart)->exists();
        if ($existingThisWeek && !$force) {
            $this->warn('Relay mappings for this week already exist. Use --force to override.');
            return 0;
        }

        // Get active rotating relays
        $rotatingRelays = Relay::where('is_active', 1)->where('is_rotating', 1)->get();

        if ($rotatingRelays->isEmpty()) {
            $this->error('No active rotating relays found in Relay Master.');
            return 0;
        }

        DB::transaction(function () use ($activeSequence, $weekStart, $weekEnd, $force, $today, $rotatingRelays) {
            // Step 0: Capture who is held back BEFORE Step 1 expires the overrides
            // that may be what currently puts them on their shift.
            $heldShifts = $this->heldEmployeeShifts($rotatingRelays);

            // Step 1: Expire all open-ended overrides (from previous weeks)
            $yesterday = $today->copy()->subDay()->toDateString();
            EmployeeShiftOverride::whereNull('effective_until')
                ->where('effective_from', '<', $weekStart)
                ->update(['effective_until' => $yesterday]);

            // Step 2: Get the most recent relay mappings (current or previous week)
            // Read BEFORE deleting so --force can use them as rotation basis
            $latestMappings = RelayShiftMapping::orderBy('week_start_date', 'desc')
                ->get()
                ->unique('relay_id');

            // If forcing, remove existing mappings for this week
            if ($force) {
                RelayShiftMapping::where('week_start_date', $weekStart)->delete();
            }

            if ($latestMappings->isEmpty()) {
                // First run — seed from current employee_shift_assignments
                $this->seedInitialMappings($rotatingRelays, $activeSequence, $weekStart, $weekEnd);
            } else {
                // Rotate: each relay's shift moves to the next in the sequence.
                // Advancing every relay by one step preserves distinctness, so a
                // collision here means the previous week's mappings were already
                // inconsistent — skip and warn rather than write a duplicate.
                $claimedShifts = [];

                foreach ($rotatingRelays as $relay) {
                    $prevMapping = $latestMappings->firstWhere('relay_id', $relay->id);

                    if (!$prevMapping || !in_array($prevMapping->shift_id, $activeSequence)) {
                        continue;
                    }

                    $currentIndex = array_search($prevMapping->shift_id, $activeSequence);
                    $nextShiftId = $activeSequence[($currentIndex + 1) % count($activeSequence)];

                    if (in_array($nextShiftId, $claimedShifts)) {
                        $this->warn("Relay {$relay->name}: Shift {$nextShiftId} already claimed this week. Skipping.");
                        continue;
                    }
                    $claimedShifts[] = $nextShiftId;

                    RelayShiftMapping::create([
                        'week_start_date' => $weekStart,
                        'week_end_date' => $weekEnd,
                        'relay_id' => $relay->id,
                        'shift_id' => $nextShiftId,
                    ]);

                    $this->line("Relay {$relay->name}: Shift {$prevMapping->shift_id} -> Shift {$nextShiftId}");
                }
            }

            // Step 3: Keep held employees on their current shift for the new week
            $this->holdEmployees($heldShifts, $weekStart);

            // Step 4: Sync employee_shift_assignments for backward compatibility
            $this->syncEmployeeAssignments($weekStart, array_keys($heldShifts));
        });

        $this->info('Relay-based shift rotation completed successfully.');
        return 0;
    }

    /**
     * Shift each rotating-relay employee must stay on, keyed by employee_id, for
     * those still actively deployed on a shift plan that has not been closed.
     *
     * Rotating them would move the shift they resolve to out from under the open
     * plan's workforce, machine allocations and attendance, the same reason the
     * Shift Rotation module refuses to change them. They rejoin their relay's
     * rotation on the first run after the plan is closed.
     */
    private function heldEmployeeShifts($rotatingRelays)
    {
        $deployments = ShiftWorkforceDeployment::active()
            ->whereHas('shiftPlan', function ($q) {
                $q->notClosed();
            })
            ->whereHas('employee', function ($q) use ($rotatingRelays) {
                $q->where('is_active', 1)->whereIn('relay_id', $rotatingRelays->pluck('id'));
            })
            ->with(['employee', 'shiftPlan'])
            ->get()
            ->sortByDesc(function ($deployment) {
                return $deployment->shiftPlan->planning_date;
            })
            ->unique('employee_id');

        $held = [];

        foreach ($deployments as $deployment) {
            // Hold them on the shift of the plan they are standing on. A borrowed
            // employee works another relay's plan, so keep their own shift as it
            // resolved on that date instead.
            $shiftId = $deployment->is_borrowed
                ? $deployment->employee->getShiftIdForDate($deployment->shiftPlan->planning_date->toDateString())
                : $deployment->shiftPlan->shift_id;
            if (!$shiftId) {
                continue;
            }

            $held[$deployment->employee_id] = $shiftId;

            $plan = $deployment->shiftPlan;
            $this->warn("Employee {$deployment->employee->name}: deployed on open shift plan "
                . ($plan->reference_no ?: "#{$plan->id}") . ". Holding on Shift {$shiftId}.");
        }

        return $held;
    }

    /**
     * Write an open-ended override from the new week's start for every held
     * employee. It outranks the relay mapping, and Step 1 of the next run expires
     * it so they are re-evaluated every week.
     */
    private function holdEmployees(array $heldShifts, string $weekStart)
    {
        foreach ($heldShifts as $employeeId => $shiftId) {
            // A deliberate override already planned for the new week wins.
            $planned = EmployeeShiftOverride::where('employee_id', $employeeId)
                ->where('effective_from', '>=', $weekStart)
                ->where(function ($q) {
                    $q->whereNull('reason')->orWhere('reason', '!=', self::HOLD_REASON);
                })
                ->exists();
            if ($planned) {
                continue;
            }

            EmployeeShiftOverride::updateOrCreate(
                [
                    'employee_id' => $employeeId,
                    'effective_from' => $weekStart,
                    'reason' => self::HOLD_REASON,
                ],
                [
                    'effective_until' => null,
                    'shift_id' => $shiftId,
                ]
            );
        }
    }

    /**
     * Seed initial relay mappings from current employee_shift_assignments.
     * Used on the very first run when no relay mappings exist yet.
     */
    private function seedInitialMappings($rotatingRelays, array $activeSequence, string $weekStart, string $weekEnd)
    {
        $this->info('No previous relay mappings found. Seeding from current employee assignments...');

        // Each relay's most common shift is derived independently, so two relays
        // can land on the same shift. Only the first may claim it.
        $claimedShifts = [];

        foreach ($rotatingRelays as $relay) {
            // Find the most common shift_id for employees in this relay
            $mostCommonShiftId = EmployeeShiftAssignment::whereHas('employee', function ($q) use ($relay) {
                $q->where('is_active', 1)->where('relay_id', $relay->id);
            })
                ->select('shift_id', DB::raw('count(*) as cnt'))
                ->groupBy('shift_id')
                ->orderByDesc('cnt')
                ->value('shift_id');

            if ($mostCommonShiftId && in_array($mostCommonShiftId, $claimedShifts)) {
                $this->warn("Shift {$mostCommonShiftId} already claimed this week. Skipping Relay {$relay->name}.");
                continue;
            }

            if ($mostCommonShiftId && in_array($mostCommonShiftId, $activeSequence)) {
                $claimedShifts[] = $mostCommonShiftId;

                RelayShiftMapping::create([
                    'week_start_date' => $weekStart,
                    'week_end_date' => $weekEnd,
                    'relay_id' => $relay->id,
                    'shift_id' => $mostCommonShiftId,
                ]);

                $this->line("Seeded Relay {$relay->name}: Shift {$mostCommonShiftId}");
            } else {
                $this->warn("Could not determine current shift for Relay {$relay->name}. Skipping.");
            }
        }
    }

    /**
     * Sync employee_shift_assignments from relay mappings for backward compatibility.
     * This ensures all existing code that reads employee_shift_assignments still works.
     */
    private function syncEmployeeAssignments(string $weekStart, array $heldEmployeeIds = [])
    {
        $mappings = RelayShiftMapping::where('week_start_date', $weekStart)->get();
        $rotatedCount = 0;

        foreach ($mappings as $mapping) {
            $employees = Employee::where('is_active', 1)
                ->where('relay_id', $mapping->relay_id)
                ->with('currentShiftAssignment')
                ->get();

            foreach ($employees as $employee) {
                if (in_array($employee->id, $heldEmployeeIds)) {
                    continue;
                }

                $currentAssignment = $employee->currentShiftAssignment;

                if ($currentAssignment) {
                    if ($currentAssignment->shift_id != $mapping->shift_id) {
                        $currentAssignment->update([
                            'shift_id' => $mapping->shift_id,
                            'from_date' => $weekStart,
                            'to_date' => null,
                        ]);
                        $rotatedCount++;
                    }
                } else {
                    EmployeeShiftAssignment::create([
                        'employee_id' => $employee->id,
                        'shift_id' => $mapping->shift_id,
                        'from_date' => $weekStart,
                        'to_date' => null,
                    ]);
                    $rotatedCount++;
                }
            }
        }

        $this->line("Synced legacy employee shift assignments for {$rotatedCount} employees.");
    }
}
