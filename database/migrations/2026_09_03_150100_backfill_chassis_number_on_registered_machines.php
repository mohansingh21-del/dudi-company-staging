<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class BackfillChassisNumberOnRegisteredMachines extends Migration
{
    /**
     * Run the migrations.
     *
     * Fills chassis_number on machines registered before that column was what
     * the syncs matched on.
     *
     * telematics:register-chassis wrote the chassis into equipment_name only,
     * so those rows exist, look correct in a listing, and still resolve to
     * nothing - the syncs read chassis_number. That failure is invisible:
     * readings are stored either way, just with a null machine link.
     *
     * Only values proven to be chassis numbers are copied - ones the feeds have
     * actually reported. A friendly label like "Dump-3" must never be written
     * into a chassis column.
     *
     * @return void
     */
    public function up()
    {
        $known = DB::table('equipment_fuel_readings')->distinct()->pluck('chassis_number')
            ->merge(DB::table('equipment_location_readings')->distinct()->pluck('chassis_number'))
            ->merge(DB::table('truck_connect_readings')->distinct()->pluck('vin'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($known)) {
            return;
        }

        foreach (array_chunk($known, 500) as $batch) {
            DB::table('equipment_names')
                ->whereNull('chassis_number')
                ->whereIn('equipment_name', $batch)
                ->update(['chassis_number' => DB::raw('equipment_name')]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Not reversed: the values are derived, and clearing them would put the
     * machine master back into the state that hid the problem.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
