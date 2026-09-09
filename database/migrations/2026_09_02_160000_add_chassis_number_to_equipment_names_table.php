<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddChassisNumberToEquipmentNamesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Gives machines a real chassis column instead of overloading
     * equipment_name with it.
     *
     * Until now the VECV syncs matched telemetry by comparing the vendor's
     * chassisNo against equipment_names.equipment_name, which is the column
     * that holds the label an operator reads ("Dump-3"). That worked only for
     * as long as somebody kept typing chassis numbers into the name field, and
     * it meant a machine could not have both a readable name and a chassis.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('equipment_names', function (Blueprint $table) {
            // Nullable: not every machine is a telematics-equipped vehicle.
            // Dozers, pumps and anything off the VECV feed simply leave it
            // empty, and must not be blocked from being registered.
            $table->string('chassis_number', 32)->nullable()->after('equipment_name');

            // One machine per chassis. Two rows sharing one chassis would make
            // the telemetry lookup ambiguous and silently attach readings to
            // whichever row the query happened to return first.
            $table->unique('chassis_number', 'equipment_names_chassis_number_unique');
        });

        // Backfill from equipment_name, but only where that value is provably a
        // chassis - it appears in telemetry already received from VECV. Copying
        // every name blindly would write friendly labels into a chassis column
        // and quietly break the uniqueness guarantee above.
        $known = DB::table('equipment_fuel_readings')->distinct()->pluck('chassis_number')
            ->merge(DB::table('equipment_location_readings')->distinct()->pluck('chassis_number'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! empty($known)) {
            DB::table('equipment_names')
                ->whereNull('chassis_number')
                ->whereIn('equipment_name', $known)
                ->update(['chassis_number' => DB::raw('equipment_name')]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('equipment_names', function (Blueprint $table) {
            $table->dropUnique('equipment_names_chassis_number_unique');
            $table->dropColumn('chassis_number');
        });
    }
}
