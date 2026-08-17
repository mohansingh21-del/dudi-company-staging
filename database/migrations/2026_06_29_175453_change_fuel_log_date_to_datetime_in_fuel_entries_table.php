<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangeFuelLogDateToDatetimeInFuelEntriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('fuel_entries', function (Blueprint $table) {
            $table->dateTime('fuel_log_date')->nullable()->change();
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
            $table->date('fuel_log_date')->nullable()->change();
        });
    }
}
