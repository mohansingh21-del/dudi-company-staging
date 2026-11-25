<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTruckConnectReadingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per Truck Connect "daas" record. Unlike the VECV feed, which
     * splits fuel and position across two endpoints and two tables, Truck
     * Connect returns both in a single payload, so a single table holds it.
     *
     * Readings are immutable point-in-time facts: inserted, never updated.
     *
     * Three vendor values are ambiguous and are stored in *_raw columns exactly
     * as sent, with no conversion. Interpretation happens at read time against
     * config/truckconnect.php, so answering a question later fixes rows that
     * are already stored. Normalising on the way in would bake a guess into the
     * data and require a backfill to undo.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('truck_connect_readings', function (Blueprint $table) {
            $table->id();

            // Vehicle identity. Matched against vehicles.chassis_number - NOT
            // against equipment_names, which holds friendly labels ("Dump-3")
            // rather than chassis numbers despite what the VECV services assume.
            $table->string('vin', 32);

            // Resolved at ingest; null when the VIN is not registered yet.
            // Readings are still stored so nothing is lost while the vehicle
            // master is being filled in.
            $table->unsignedBigInteger('vehicle_id')->nullable();

            // Sent as RegistrationId, but in the observed payload it is a copy
            // of the VIN rather than a number plate. Stored as sent and never
            // relied on for display - the plate comes from the vehicles table.
            $table->string('registration_id', 32)->nullable();

            // Telematics unit IMEI. Hardware tracing only: a box can be moved
            // between vehicles, so this must never key a reading.
            $table->string('device_imei', 32)->nullable();

            /*
             * Status
             */

            // IGN, sent as "0"/"1".
            $table->boolean('ignition')->nullable();

            // Observed: OFFLINE. The vocabulary differs from VECV's
            // MOVING/IDLING/STOPPED, so the two are not comparable.
            $table->string('vehicle_status', 16)->nullable();

            // MessageStatus, observed as "H" - suspected live/history marker,
            // unconfirmed. Stored verbatim so the guess is not baked in.
            $table->string('message_status', 4)->nullable();

            // CRT, observed as "000". Meaning not documented by the vendor.
            $table->string('crt', 8)->nullable();

            /*
             * Position
             */

            // Matches the precision used by site_points and the VECV tables.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // HEAD: degrees clockwise from north.
            $table->decimal('heading', 5, 2)->nullable();

            // ALT: metres. Not on the VECV location feed.
            $table->decimal('altitude', 8, 2)->nullable();

            /*
             * Engine
             */

            $table->decimal('vehicle_speed', 6, 2)->nullable();

            // Not on the VECV feed. Note the sample carries a live idle RPM
            // (806.39) on a vehicle reporting OFFLINE with IGN 0, so these are
            // last-known values and must be read together with staleness.
            $table->decimal('engine_rpm', 8, 2)->nullable();

            /*
             * UNCONFIRMED UNITS - stored raw, converted only at read time.
             *
             * Widened well beyond the VECV equivalents on purpose: the observed
             * 30297536.0 is probably metres, and the column must hold whichever
             * unit the answer turns out to be without silently truncating.
             */

            $table->decimal('odometer_raw', 16, 2)->nullable();
            $table->decimal('fuel_level_raw', 10, 2)->nullable();

            // AdlLevel - AdBlue/DEF.
            $table->decimal('adblue_level_raw', 10, 2)->nullable();

            /*
             * Harsh driving counters
             *
             * Vendor spells the middle one "HarshBreaking"; do not "correct"
             * the key when mapping. Whether these are cumulative totals or
             * per-message flags is unconfirmed, so they are stored as sent and
             * no daily aggregate is derived from a single row.
             */

            $table->unsignedInteger('harsh_acceleration')->nullable();
            $table->unsignedInteger('harsh_braking')->nullable();
            $table->unsignedInteger('harsh_cornering')->nullable();

            /*
             * Time
             */

            // GpsTime exactly as sent, e.g. "2026-09-01 05:03:56". Kept as a
            // string because its zone is unknown; it is also the dedupe key,
            // which makes deduplication immune to the timezone answer.
            $table->string('gps_time_raw', 32);

            // gps_time_raw converted from the configured source zone into the
            // app timezone, so it is comparable to created_at, shift times and
            // Carbon::now() - the same rule as the VECV tables.
            //
            // Nullable, and left null while truckconnect.source_timezone is
            // 'unknown'. A null here means "not yet interpretable", never
            // "no data": gps_time_raw always holds the vendor's value.
            $table->timestamp('reported_at')->nullable();

            $table->json('raw')->nullable();

            $table->timestamps();

            $table->foreign('vehicle_id')
                ->references('id')
                ->on('vehicles')
                ->onDelete('set null');

            // Dedupe on the raw vendor timestamp rather than reported_at. The
            // API returns the last known reading on every fetch, so a vehicle
            // that has not reported since the previous click adds no new row -
            // and because the key does not depend on the timezone answer,
            // changing that answer can never introduce duplicates.
            $table->unique(['vin', 'gps_time_raw'], 'tcr_vin_gps_time_unique');

            $table->index(['vin', 'reported_at']);
            $table->index(['vehicle_id', 'reported_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('truck_connect_readings');
    }
}
