<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterHolidaysTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['date', 'title']);
            $table->string('holiday_name');
            $table->date('holiday_date');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('holiday_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['holiday_name', 'holiday_date', 'site_id', 'holiday_type']);
            $table->date('date')->unique();
            $table->string('title');
        });
    }
}
