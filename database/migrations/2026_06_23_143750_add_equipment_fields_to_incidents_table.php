<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEquipmentFieldsToIncidentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('incidents', function (Blueprint $table) {

            $table->foreignId('equipment_id')
                ->nullable()
                ->after('location_id')
                ->constrained('equipments')
                ->nullOnDelete();

            $table->foreignId('equipment_name_id')
                ->nullable()
                ->after('equipment_id')
                ->constrained('equipment_names')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('incidents', function (Blueprint $table) {

            $table->dropForeign(['equipment_id']);
            $table->dropForeign(['equipment_name_id']);

            $table->dropColumn([
                'equipment_id',
                'equipment_name_id'
            ]);
        });
    }
}
