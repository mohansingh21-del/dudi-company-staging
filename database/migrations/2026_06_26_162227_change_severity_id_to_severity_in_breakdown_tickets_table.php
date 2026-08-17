<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeSeverityIdToSeverityInBreakdownTicketsTable extends Migration
{
    public function up()
    {
        Schema::table('breakdown_tickets', function (Blueprint $table) {
            $table->dropColumn('severity_id');
            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])->after('breakdown_type_id');
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
            $table->dropColumn('severity');
            $table->unsignedBigInteger('severity_id')->after('breakdown_type_id');
        });
    }
}
