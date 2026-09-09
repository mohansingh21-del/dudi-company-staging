<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class BackfillMachineLinkOnTruckConnectReadings extends Migration
{
    /**
     * Run the migrations.
     *
     * Links Truck Connect readings taken before their machine was registered.
     *
     * equipment_name_id is resolved once, at ingest, so any reading stored
     * while the VIN was unknown keeps a null forever - even after the machine
     * exists. Runs after the chassis backfill so equipment_names.chassis_number
     * is populated by the time this matches against it.
     *
     * @return void
     */
    public function up()
    {
        DB::table('truck_connect_readings')
            ->join('equipment_names', 'equipment_names.chassis_number', '=', 'truck_connect_readings.vin')
            ->whereNull('truck_connect_readings.equipment_name_id')
            ->whereNotNull('equipment_names.chassis_number')
            ->update(['truck_connect_readings.equipment_name_id' => DB::raw('equipment_names.id')]);
    }

    /**
     * Reverse the migrations.
     *
     * Not reversed - the values are derived, and re-running up() rebuilds them.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
