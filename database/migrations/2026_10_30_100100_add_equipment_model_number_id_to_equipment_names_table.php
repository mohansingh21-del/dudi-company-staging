<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEquipmentModelNumberIdToEquipmentNamesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('equipment_names', function (Blueprint $table) {
            // Nullable: machines registered before the model master existed
            // have no model yet.
            $table->unsignedBigInteger('equipment_model_number_id')
                ->nullable()
                ->after('equipment_id');

            $table->foreign('equipment_model_number_id')
                ->references('id')
                ->on('equipment_model_numbers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('equipment_names', function (Blueprint $table) {
            $table->dropForeign(['equipment_model_number_id']);
            $table->dropColumn('equipment_model_number_id');
        });
    }
}
