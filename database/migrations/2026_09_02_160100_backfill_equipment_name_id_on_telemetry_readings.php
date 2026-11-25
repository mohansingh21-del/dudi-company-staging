<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class BackfillEquipmentNameIdOnTelemetryReadings extends Migration
{
    /**
     * Run the migrations.
     *
     * Links telemetry stored before equipment_names.chassis_number existed.
     *
     * equipment_name_id is resolved once, at ingest. Every reading taken while
     * the chassis lookup was broken therefore kept a null there permanently,
     * and stays invisible to EquipmentFuelReading::nearest() and the
     * forMachine() scope even though the machine is now registered.
     *
     * The dashboard joins equipment_names live and was never affected, which
     * is exactly why this would otherwise go unnoticed: the screen would look
     * complete while shift-boundary lookups quietly returned nothing.
     *
     * @return void
     */
    public function up()
    {
        foreach (['equipment_fuel_readings', 'equipment_location_readings'] as $table) {
            DB::table($table)
                ->join('equipment_names', 'equipment_names.chassis_number', '=', $table . '.chassis_number')
                ->whereNull($table . '.equipment_name_id')
                ->whereNotNull('equipment_names.chassis_number')
                ->update([$table . '.equipment_name_id' => DB::raw('equipment_names.id')]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Not reversed. Clearing equipment_name_id again would leave the data in a
     * state the application no longer produces, and the values are derived -
     * re-running up() rebuilds them exactly.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
