<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class ChangeDelayTimesToTimeColumns extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Convert existing datetime values to time-only before altering the column type
        DB::statement("ALTER TABLE `delays` MODIFY COLUMN `start_time` TIME NOT NULL");
        DB::statement("ALTER TABLE `delays` MODIFY COLUMN `end_time` TIME NULL");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement("ALTER TABLE `delays` MODIFY COLUMN `start_time` DATETIME NOT NULL");
        DB::statement("ALTER TABLE `delays` MODIFY COLUMN `end_time` DATETIME NULL");
    }
}
