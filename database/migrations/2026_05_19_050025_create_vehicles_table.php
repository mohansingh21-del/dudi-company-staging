<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_type_id')->constrained('vehicle_types')->onDelete('cascade');
            $table->string('vehicle_body_type')->nullable();
            $table->string('vehicle_length')->nullable();
            $table->string('vehicle_condition')->nullable();
            $table->foreignId('vehicle_manufacturer_id')->constrained('vehicle_manufacturers')->onDelete('cascade');
            $table->foreignId('vehicle_model_id')->constrained('vehicle_models')->onDelete('cascade');
            $table->string('registration_date')->nullable();
            $table->string('body_total_volumetric_capacity')->nullable();
            $table->string('chassis_number')->nullable();
            $table->string('engine_number')->nullable();
            $table->string('color')->nullable();
            $table->string('wheel_base')->nullable();
            $table->string('emission_norm')->nullable();
            $table->string('horse_power')->nullable();
            $table->string('laden_weight')->nullable();
            $table->string('unladen_weight')->nullable();
            $table->string('gross_vehicle_weight')->nullable();
            $table->string('fuel_type')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vehicles');
    }
};
