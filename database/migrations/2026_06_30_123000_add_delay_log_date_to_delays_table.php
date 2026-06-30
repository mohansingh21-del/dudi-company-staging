<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDelayLogDateToDelaysTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('delays', function (Blueprint $table) {
            $table->dateTime('delay_log_date')->nullable()->after('shift_name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('delays', function (Blueprint $table) {
            $table->dropColumn(['delay_log_date']);
        });
    }
}
