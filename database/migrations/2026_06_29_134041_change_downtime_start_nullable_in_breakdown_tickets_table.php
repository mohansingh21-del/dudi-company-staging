<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeDowntimeStartNullableInBreakdownTicketsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('breakdown_tickets', function (Blueprint $table) {
            $table->dateTime('downtime_start')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('breakdown_tickets', function (Blueprint $table) {
            $table->dateTime('downtime_start')->nullable(false)->change();
        });
    }
}
