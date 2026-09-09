<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEquipmentColumnsToFuelEntriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('fuel_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('equipment_id')->nullable()->after('equipment_allocation_id');
            $table->unsignedBigInteger('equipment_name_id')->nullable()->after('equipment_id');

            $table->foreign('equipment_id')
                ->references('id')
                ->on('equipments')
                ->onDelete('restrict');

            $table->foreign('equipment_name_id')
                ->references('id')
                ->on('equipment_names')
                ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('fuel_entries', function (Blueprint $table) {
            $table->dropForeign(['equipment_id']);
            $table->dropForeign(['equipment_name_id']);
            $table->dropColumn(['equipment_id', 'equipment_name_id']);
        });
    }
}
