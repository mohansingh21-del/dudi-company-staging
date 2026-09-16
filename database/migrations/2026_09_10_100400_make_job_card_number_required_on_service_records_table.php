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
 *
 * Services recorded while the column was still optional have no job card to put
 * in it, and under STRICT_TRANS_TABLES MySQL refuses to make a column NOT NULL
 * while rows hold NULL (errno 1138). They are stamped with their own ticket
 * number under a LEGACY- prefix first: unique, so it cannot collide with a real
 * job card, and obvious enough on screen that whoever knows the real number can
 * replace it.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement(
            "UPDATE `service_records` SET `job_card_number` = CONCAT('LEGACY-', `ticket_number`)
             WHERE `job_card_number` IS NULL OR `job_card_number` = ''"
        );

        DB::statement('ALTER TABLE `service_records` MODIFY `job_card_number` VARCHAR(64) NOT NULL');
    }

    public function down()
    {
        DB::statement('ALTER TABLE `service_records` MODIFY `job_card_number` VARCHAR(64) NULL');

        DB::statement(
            "UPDATE `service_records` SET `job_card_number` = NULL
             WHERE `job_card_number` LIKE 'LEGACY-%'"
        );
    }
};
