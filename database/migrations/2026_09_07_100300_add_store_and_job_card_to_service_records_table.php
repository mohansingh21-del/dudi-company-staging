<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service record that draws parts from outside draws them from exactly one
 * store, against exactly one job card that store raised. Both therefore sit on
 * the record, not on each spare part row.
 *
 * Unrelated to vecv_service_histories.job_card_number, which is a dealer's own
 * job card pulled from VECV. This one is keyed by hand against an outside
 * store, so it carries no unique constraint.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->string('job_card_number', 64)->nullable()->after('ticket_number');
            $table->unsignedBigInteger('store_id')->nullable()->after('site_id');

            $table->index('job_card_number');
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropIndex(['job_card_number']);
            $table->dropColumn(['store_id', 'job_card_number']);
        });
    }
};
