<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PointTruckConnectReadingsAtEquipmentNames extends Migration
{
    /**
     * Run the migrations.
     *
     * Moves the machine link from vehicles to equipment_names.
     *
     * Truck Connect readings originally resolved their VIN against
     * vehicles.chassis_number while the VECV feeds resolved theirs against
     * equipment_names.chassis_number. Two masters for the same question meant a
     * machine could be registered for one feed and invisible to the other, and
     * nothing would have reported the inconsistency - both feeds simply store
     * unmatched readings.
     *
     * equipment_names wins because that is what the rest of the system counts a
     * machine by: shift allocations, fuel entries and the dashboard all key on
     * equipment_name_id.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('truck_connect_readings', function (Blueprint $table) {
            $table->unsignedBigInteger('equipment_name_id')->nullable()->after('vin');

            $table->foreign('equipment_name_id')
                ->references('id')
                ->on('equipment_names')
                ->onDelete('set null');

            $table->index(['equipment_name_id', 'reported_at']);
        });

        // Resolve whatever is already registered. Readings taken before a
        // machine exists keep a null and are picked up by a later sync.
        DB::table('truck_connect_readings')
            ->join('equipment_names', 'equipment_names.chassis_number', '=', 'truck_connect_readings.vin')
            ->whereNotNull('equipment_names.chassis_number')
            ->update(['truck_connect_readings.equipment_name_id' => DB::raw('equipment_names.id')]);

        Schema::table('truck_connect_readings', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropColumn('vehicle_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('truck_connect_readings', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable()->after('vin');

            $table->foreign('vehicle_id')
                ->references('id')
                ->on('vehicles')
                ->onDelete('set null');
        });

        Schema::table('truck_connect_readings', function (Blueprint $table) {
            $table->dropForeign(['equipment_name_id']);
            $table->dropIndex(['equipment_name_id', 'reported_at']);
            $table->dropColumn('equipment_name_id');
        });
    }
}
