<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFuelLogDateAndShiftIdToFuelEntriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('fuel_entries', function (Blueprint $table) {
            $table->date('fuel_log_date')->nullable()->after('shift_plan_id');
            $table->unsignedBigInteger('shift_id')->nullable()->after('fuel_log_date');

            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts')
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
        Schema::table('fuel_entries', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn(['fuel_log_date', 'shift_id']);
        });
    }
}
