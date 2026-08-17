<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Downtime capture moved out of the breakdown module and into service records.
 *
 * A service record now owns the downtime window. Filling both ends completes the
 * service, and for a breakdown-linked service it also closes the ticket and
 * pushes the same window onto breakdown_tickets so the existing dashboards
 * (MTTR, total downtime, equipment availability) keep working unchanged.
 */
class AddDowntimeToServiceRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->dateTime('downtime_start')->nullable()->after('time_gap_months');
            $table->dateTime('downtime_end')->nullable()->after('downtime_start');
            $table->unsignedInteger('downtime_minutes')->nullable()->after('downtime_end');

            $table->index(['machine_id', 'downtime_start']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->dropIndex(['machine_id', 'downtime_start']);
            $table->dropColumn(['downtime_start', 'downtime_end', 'downtime_minutes']);
        });
    }
}
