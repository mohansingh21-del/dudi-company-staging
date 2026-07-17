<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftOverride;
use App\Models\RelayShiftMapping;
use App\Models\Relay;
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
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
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
                // Rotate: each relay's shift moves to the next in the sequence
                foreach ($rotatingRelays as $relay) {
                    $prevMapping = $latestMappings->firstWhere('relay_id', $relay->id);

                    if (!$prevMapping || !in_array($prevMapping->shift_id, $activeSequence)) {
                        continue;
                    }

                    $currentIndex = array_search($prevMapping->shift_id, $activeSequence);
                    $nextShiftId = $activeSequence[($currentIndex + 1) % count($activeSequence)];

                    RelayShiftMapping::create([
                        'week_start_date' => $weekStart,
                        'week_end_date' => $weekEnd,
                        'relay_id' => $relay->id,
                        'shift_id' => $nextShiftId,
                    ]);

                    $this->line("Relay {$relay->name}: Shift {$prevMapping->shift_id} -> Shift {$nextShiftId}");
                }
            }

            // Step 3: Sync employee_shift_assignments for backward compatibility
            $this->syncEmployeeAssignments($weekStart);
        });

        $this->info('Relay-based shift rotation completed successfully.');
        return 0;
    }

    /**
     * Seed initial relay mappings from current employee_shift_assignments.
     * Used on the very first run when no relay mappings exist yet.
     */
    private function seedInitialMappings($rotatingRelays, array $activeSequence, string $weekStart, string $weekEnd)
    {
        $this->info('No previous relay mappings found. Seeding from current employee assignments...');

        foreach ($rotatingRelays as $relay) {
            // Find the most common shift_id for employees in this relay
            $mostCommonShiftId = EmployeeShiftAssignment::whereHas('employee', function ($q) use ($relay) {
                $q->where('is_active', 1)->where('relay_id', $relay->id);
            })
                ->select('shift_id', DB::raw('count(*) as cnt'))
                ->groupBy('shift_id')
                ->orderByDesc('cnt')
                ->value('shift_id');

            if ($mostCommonShiftId && in_array($mostCommonShiftId, $activeSequence)) {
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
    private function syncEmployeeAssignments(string $weekStart)
    {
        $mappings = RelayShiftMapping::where('week_start_date', $weekStart)->get();
        $rotatedCount = 0;

        foreach ($mappings as $mapping) {
            $employees = Employee::where('is_active', 1)
                ->where('relay_id', $mapping->relay_id)
                ->with('currentShiftAssignment')
                ->get();

            foreach ($employees as $employee) {
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
