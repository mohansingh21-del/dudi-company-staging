<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Restores the four fixed Form E blocks in `leave_types`.
 *
 * These rows are normally created by the 2026_08_12 migration, so a live
 * database that has had them deleted (or nulled `register_group`) can no longer
 * re-run that migration to get them back — the leave-type dropdown then comes
 * back empty because every list is scoped by `LeaveType::onRegister()`
 * (`whereNotNull('register_group')`).
 *
 * This seeder is idempotent: it keys on `register_group`, keeps any quota the
 * admin has already configured, and only fixes the name / category / active
 * flag. Safe to run on production repeatedly.
 */
class LeaveTypeSeeder extends Seeder
{
    public function run()
    {
        $blocks = [
            'compensatory_rest' => ['name' => 'Compensatory Rest', 'leave_category' => 'paid'],
            'earned' => ['name' => 'Earned Leave', 'leave_category' => 'paid', 'allowed_days' => 12],
            'medical' => ['name' => 'Medical Leave', 'leave_category' => 'paid'],
            'other' => ['name' => 'Other Leave', 'leave_category' => 'unpaid'],
        ];

        foreach ($blocks as $group => $attributes) {
            // Match an already-grouped row first; fall back to the canonical name
            // for a legacy row whose register_group was dropped or nulled.
            $existing = LeaveType::where('register_group', $group)->first()
                ?: LeaveType::whereNull('register_group')
                    ->where('name', $attributes['name'])
                    ->first();

            if ($existing) {
                $existing->update([
                    'name' => $attributes['name'],
                    'register_group' => $group,
                    'leave_category' => $attributes['leave_category'],
                    'is_active' => true,
                ]);

                $this->log("Leave type restored: {$attributes['name']} ({$group})");
                continue;
            }

            LeaveType::create($attributes + [
                'register_group' => $group,
                'allowed_days' => $attributes['allowed_days'] ?? 0,
                'is_active' => true,
            ]);

            $this->log("Leave type created: {$attributes['name']} ({$group})");
        }
    }

    /**
     * Console output, guarded for when the seeder runs outside an Artisan
     * command (e.g. from a tinker call) where $this->command is null.
     */
    private function log(string $message)
    {
        if ($this->command) {
            $this->command->info($message);
        }
    }
}
