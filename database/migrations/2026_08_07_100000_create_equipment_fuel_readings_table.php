<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEquipmentFuelReadingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('equipment_fuel_readings', function (Blueprint $table) {
            $table->id();

            // As returned by VECV. Matched against equipment_names.equipment_name,
            // which is where this project stores the chassis number.
            $table->string('chassis_number', 32);

            // Resolved at ingest. Null when the chassis is not yet registered as
            // a machine - readings are still stored so nothing is lost.
            $table->unsignedBigInteger('equipment_name_id')->nullable();

            // Arrives as "" on every vehicle observed so far; normalised to null.
            $table->string('reg_no', 32)->nullable();

            $table->string('fuel_type', 16)->nullable();
            $table->string('vehicle_status', 16)->nullable();

            // Matches the precision used by site_points.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->decimal('vehicle_speed', 6, 2)->nullable();
            $table->decimal('odometer', 12, 2)->nullable();
            $table->decimal('engine_operating_hours', 12, 2)->nullable();

            $table->decimal('fuel_level_pct', 5, 2)->nullable();
            $table->decimal('fuel_level_ltr', 10, 2)->nullable();
            $table->decimal('def_level_ltr', 10, 2)->nullable();

            // Cumulative litres burned. Delivered as a string by the API and
            // cast on ingest. This is the authoritative consumption source -
            // unlike tank level it survives refuelling and sloshing.
            $table->decimal('lifetime_fuel_consumed', 14, 2)->nullable();

            // EV only. Absent from diesel payloads.
            $table->decimal('soc_level', 5, 2)->nullable();
            $table->decimal('battery_temperature', 6, 2)->nullable();
            $table->decimal('co2_saving', 12, 2)->nullable();

            // Derived from epochTime and converted to the app timezone, so it
            // is directly comparable to created_at and to shift times. The
            // lastUpdated field is the same instant and is not stored twice.
            $table->timestamp('reported_at');

            $table->json('raw')->nullable();

            $table->timestamps();

            $table->foreign('equipment_name_id')
                ->references('id')
                ->on('equipment_names')
                ->onDelete('set null');

            // Dedupe key. The API returns the last known reading every poll, so
            // a vehicle that has stopped reporting simply adds no new rows.
            $table->unique(['chassis_number', 'reported_at'], 'efr_chassis_reported_unique');

            $table->index(['chassis_number', 'reported_at']);
            $table->index(['equipment_name_id', 'reported_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('equipment_fuel_readings');
    }
}
