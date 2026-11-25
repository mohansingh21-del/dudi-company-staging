<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-resolves equipment_name_id on stored telematics readings.
 *
 * Why this exists
 * ---------------
 * equipment_name_id is resolved once, at ingest. A reading stored before its
 * machine was registered keeps a null there forever, even after the machine
 * appears - and nothing reports it, because readings are stored either way and
 * the fleet dashboard joins equipment_names live rather than reading the stored
 * id. The screen looks complete while nearest() and the forMachine() scopes
 * quietly return nothing.
 *
 * The backfill migrations do this once, at deploy time. On a fresh
 * installation that is too early: the migrations run, then chassis are
 * registered, then the feeds are synced - so anything already stored at step
 * one is never linked. This command closes that window and is safe to run
 * whenever, as often as needed.
 *
 * Run it after telematics:register-chassis, and any time a machine's chassis
 * number is corrected.
 */
class RelinkTelematicsReadingsCommand extends Command
{
    protected $signature = 'telematics:relink-readings {--dry-run : Report what would be linked without writing}';

    protected $description = 'Resolve equipment_name_id on telematics readings stored before their machine was registered';

    /**
     * Reading tables and the column each keys its machine on.
     */
    protected $tables = [
        'equipment_fuel_readings'     => 'chassis_number',
        'equipment_location_readings' => 'chassis_number',
        'truck_connect_readings'      => 'vin',
    ];

    /**
     * @return int
     */
    public function handle()
    {
        $rows = [];

        foreach ($this->tables as $table => $key) {
            $unlinked = DB::table($table)->whereNull('equipment_name_id')->count();

            // How many of those could be linked right now - i.e. their chassis
            // is registered. The rest have no machine yet, which is a
            // register-chassis job, not this one.
            $linkable = DB::table($table)
                ->join('equipment_names', 'equipment_names.chassis_number', '=', $table . '.' . $key)
                ->whereNull($table . '.equipment_name_id')
                ->whereNotNull('equipment_names.chassis_number')
                ->count();

            if (! $this->option('dry-run') && $linkable > 0) {
                DB::table($table)
                    ->join('equipment_names', 'equipment_names.chassis_number', '=', $table . '.' . $key)
                    ->whereNull($table . '.equipment_name_id')
                    ->whereNotNull('equipment_names.chassis_number')
                    ->update([$table . '.equipment_name_id' => DB::raw('equipment_names.id')]);
            }

            $rows[] = [
                $table,
                DB::table($table)->count(),
                $unlinked,
                $linkable,
                $unlinked - $linkable,
            ];
        }

        $this->table(
            ['Table', 'Rows', 'Was unlinked', $this->option('dry-run') ? 'Linkable' : 'Linked', 'Still unlinked'],
            $rows
        );

        $remaining = array_sum(array_column($rows, 4));

        if ($remaining > 0) {
            $this->warn(sprintf(
                '%d reading(s) have a chassis that is not registered in equipment_names. '
                . 'Run telematics:register-chassis, then this command again.',
                $remaining
            ));
        }

        return 0;
    }
}
