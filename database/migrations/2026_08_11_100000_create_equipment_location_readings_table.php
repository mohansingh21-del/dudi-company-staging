<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateEquipmentLocationReadingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns mirror the observed VECV location payload exactly - that
     * endpoint returns a strictly smaller set of fields than the fuel one
     * (no engine hours, no altitude, no address), so nothing is carried over
     * from equipment_fuel_readings on the assumption it will show up later.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('equipment_location_readings', function (Blueprint $table) {
            $table->id();

            // As returned by VECV. Matched against equipment_names.equipment_name,
            // which is where this project stores the chassis number.
            $table->string('chassis_number', 32);

            // Resolved at ingest. Null when the chassis is not yet registered as
            // a machine - readings are still stored so nothing is lost.
            $table->unsignedBigInteger('equipment_name_id')->nullable();

            // Arrives as "" on every vehicle observed so far; normalised to null.
            $table->string('reg_no', 32)->nullable();

            // Observed values: MOVING, IDLING, STOPPED.
            $table->string('vehicle_status', 16)->nullable();

            // Matches the precision used by site_points. The feed delivers 5-6
            // decimals, which is already finer than the fleet needs.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->decimal('vehicle_speed', 6, 2)->nullable();

            // The reason this endpoint is worth polling alongside fuel. Whole
            // kilometres in the observed feed, but stored with decimals since
            // the vendor sends it as a float.
            $table->decimal('odometer', 12, 2)->nullable();

            // vehicleDirection: degrees clockwise from north, 0-359.9.
            $table->decimal('vehicle_direction', 5, 2)->nullable();

            // Telematics unit IMEI. Not the vehicle identity - a box can be
            // swapped between machines, so this is for hardware tracing only
            // and must never be used to key a reading.
            $table->string('device_id', 32)->nullable();

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
            $table->unique(['chassis_number', 'reported_at'], 'elr_chassis_reported_unique');

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
        Schema::dropIfExists('equipment_location_readings');
    }
}
