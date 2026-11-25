<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVecvAlertsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Events from the VECV alert log: over-speeding, over-stoppage, device
     * disconnection, and whatever else the account is subscribed to. Alerts are
     * immutable point-in-time facts - inserted, never updated.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vecv_alerts', function (Blueprint $table) {
            $table->id();

            $table->string('chassis_number', 32);

            // Resolved at ingest against equipment_names.chassis_number. Null
            // when the chassis is not registered yet; the alert is still stored
            // so nothing is lost, and telematics:relink-readings fills it in.
            $table->unsignedBigInteger('equipment_name_id')->nullable();

            $table->string('customer_id', 32)->nullable();

            // Arrives as "" on every alert observed so far; normalised to null.
            $table->string('reg_no', 32)->nullable();

            // Vendor's own two-level classification. Observed:
            //   Driving Behaviour -> OVER_SPEEDING, OVER_STOPPAGE
            //   Device Alerts     -> Device Disconnection
            // The documented set is wider (fuel refill/drain, predictive
            // uptime), so neither column is constrained to an enum.
            $table->string('alert_type', 64);

            // Note this is NOT always a code: driving-behaviour rows carry
            // OVER_SPEEDING, device rows carry the human label verbatim. Stored
            // as sent rather than normalised, because the vendor's grouping is
            // what the dashboard has to reproduce.
            $table->string('alert_sub_type_id', 64);
            $table->string('alert_sub_type', 128);

            // Absent entirely on some alert types - a device disconnection has
            // no measurement - so both are nullable and must be read with a
            // null-safe accessor, never by direct array index.
            $table->decimal('alert_value', 12, 2)->nullable();
            $table->string('alert_unit', 16)->nullable();

            // As sent, e.g. "2026-09-08T12:28:48" or "2026-09-08T14:03:23.243"
            // - the milliseconds appear on some types and not others. Kept
            // verbatim because it is also the dedupe key, which makes
            // deduplication independent of how the timestamp is parsed.
            $table->string('alert_time_raw', 32);

            // alert_time_raw in the app timezone. The feed sends no offset, but
            // it is IST: the newest alert in a live pull was three minutes old
            // against the IST clock, where reading it as UTC would have placed
            // it five and a half hours in the future.
            $table->timestamp('alerted_at')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->json('raw')->nullable();

            $table->timestamps();

            $table->foreign('equipment_name_id')
                ->references('id')
                ->on('equipment_names')
                ->onDelete('set null');

            // The feed carries no alert id, so identity is the machine, the
            // kind of event and the instant. Keyed on the raw timestamp rather
            // than alerted_at so re-reading the same window never duplicates,
            // whatever happens to the parsing.
            $table->unique(
                ['chassis_number', 'alert_sub_type_id', 'alert_time_raw'],
                'vecv_alerts_natural_key_unique'
            );

            $table->index(['alerted_at']);
            $table->index(['equipment_name_id', 'alerted_at']);
            $table->index(['alert_type', 'alerted_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vecv_alerts');
    }
}
