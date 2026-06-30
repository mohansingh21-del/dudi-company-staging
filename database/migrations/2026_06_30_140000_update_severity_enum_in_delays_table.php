<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class UpdateSeverityEnumInDelaysTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE `delays` MODIFY COLUMN `severity` ENUM('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') NOT NULL");

        // Map existing lowercase values to uppercase
        DB::statement("UPDATE `delays` SET `severity` = UPPER(`severity`) WHERE `severity` IN ('minor', 'moderate', 'major', 'critical')");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement("UPDATE `delays` SET `severity` = LOWER(`severity`) WHERE `severity` IN ('LOW', 'MEDIUM', 'HIGH', 'CRITICAL')");

        DB::statement("ALTER TABLE `delays` MODIFY COLUMN `severity` ENUM('minor', 'moderate', 'major', 'critical') NOT NULL");
    }
}
