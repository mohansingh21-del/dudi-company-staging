<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every service now carries a job card, not just the ones that drew parts from
 * an outside store.
 *
 * Raw MODIFY rather than a Blueprint ->change(): the column is indexed, and
 * doctrine/dbal rebuilds indexed columns in a way that has a habit of dropping
 * the index along the way. The index is untouched here.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement('ALTER TABLE `service_records` MODIFY `job_card_number` VARCHAR(64) NOT NULL');
    }

    public function down()
    {
        DB::statement('ALTER TABLE `service_records` MODIFY `job_card_number` VARCHAR(64) NULL');
    }
};
