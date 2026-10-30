<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEquipmentModelNumbersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('equipment_model_numbers', function (Blueprint $table) {

            $table->id();

            // The category the model belongs to, so the equipment name form
            // only offers models of the category picked.
            $table->unsignedBigInteger('equipment_id');

            $table->string('model_number', 100);

            $table->string('manufacturer')->nullable();

            $table->tinyInteger('is_active')->default(1);

            $table->timestamps();

            $table->foreign('equipment_id')
                ->references('id')
                ->on('equipments')
                ->onDelete('cascade');

            $table->unique(
                ['equipment_id', 'model_number'],
                'equipment_model_numbers_equipment_model_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('equipment_model_numbers');
    }
}
