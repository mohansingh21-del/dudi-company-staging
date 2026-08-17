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
            if (Schema::hasColumn('holidays', 'date')) {
                $table->dropColumn('date');
            }
            if (Schema::hasColumn('holidays', 'title')) {
                $table->dropColumn('title');
            }
            if (!Schema::hasColumn('holidays', 'holiday_name')) {
                $table->string('holiday_name');
            }
            if (!Schema::hasColumn('holidays', 'holiday_date')) {
                $table->date('holiday_date');
            }
            if (!Schema::hasColumn('holidays', 'site_id')) {
                $table->unsignedBigInteger('site_id')->nullable();
            }
            if (!Schema::hasColumn('holidays', 'holiday_type')) {
                $table->string('holiday_type')->nullable();
            }
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
