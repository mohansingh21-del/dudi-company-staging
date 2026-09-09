<?php

namespace Database\Seeders;

use App\Models\Equipment;
use Illuminate\Database\Seeder;

/**
 * Seeds the base equipment types in `equipments`.
 *
 * Idempotent: keyed on `name`, safe to re-run on production.
 */
class EquipmentSeeder extends Seeder
{
    public function run()
    {
        $names = [
            'Dumper',
            'Excavator',
        ];

        foreach ($names as $name) {
            Equipment::firstOrCreate(
                ['name' => $name],
                ['is_active' => 1]
            );

            $this->log("Equipment ensured: {$name}");
        }
    }

    private function log(string $message)
    {
        if ($this->command) {
            $this->command->info($message);
        }
    }
}
