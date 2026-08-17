<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class MakeMinimumWorkingHoursNullableInShiftsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->decimal('minimum_working_hours', 5, 2)->nullable()->change();
            $table->boolean('is_night_shift')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->decimal('minimum_working_hours', 5, 2)->nullable(false)->default(8)->change();
            $table->boolean('is_night_shift')->nullable(false)->default(false)->change();
        });
    }
}
