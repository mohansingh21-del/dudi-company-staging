<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeReportedByForeignKeyInBreakdownTicketsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('breakdown_tickets', function (Blueprint $table) {
            $table->dropForeign('breakdown_tickets_reported_by_foreign');

            $table->foreign('reported_by')
                ->references('id')
                ->on('employees')
                ->onDelete('restrict');
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
            $table->dropForeign('breakdown_tickets_reported_by_foreign');

            $table->foreign('reported_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
        });
    }
}
