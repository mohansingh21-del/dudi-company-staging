<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stops a site from holding two active holidays on the same date.
 *
 * The table lost its only unique index in 2026_07_13_123919, which dropped the
 * `date` column the index was on and added `holiday_date` without one. Every
 * calculation counted holiday ROWS, so each duplicate was paid as an extra day.
 *
 * Two things a plain `unique(site_id, holiday_date)` could not do:
 *
 *   1. site_id is nullable and means "all sites". MySQL treats every NULL as
 *      distinct in a unique index, so general holidays would stay unprotected.
 *      COALESCE(site_id, 0) gives them a real key.
 *
 *   2. Duplicates are retired by setting is_active = 0, not by deleting them,
 *      so the archived rows would collide with the row that was kept. The
 *      expression yields NULL for inactive rows, and a NULL index entry is
 *      never compared — inactive duplicates are unconstrained, active ones
 *      are unique.
 *
 * Run `php artisan holidays:dedupe` (then `--fix`) BEFORE this migration:
 * MySQL refuses to build the index while active duplicates exist, and the
 * failure message will not tell you which rows are at fault.
 */
return new class extends Migration
{
    const INDEX = 'holidays_active_site_date_unique';
    const COLUMN = 'holiday_key';

    public function up()
    {
        if (Schema::hasColumn('holidays', self::COLUMN)) {
            return;
        }

        $duplicates = DB::table('holidays')
            ->selectRaw('COALESCE(site_id, 0) as site_key, holiday_date, COUNT(*) as total')
            ->where('is_active', 1)
            ->groupBy('site_key', 'holiday_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Cannot add the holidays unique index: %d (site, date) combination(s) still have more '
                . 'than one active holiday. Run `php artisan holidays:dedupe` to review them and '
                . '`php artisan holidays:dedupe --fix` to retire the extras, then migrate again.',
                $duplicates->count()
            ));
        }

        DB::statement(
            'ALTER TABLE `holidays` ADD COLUMN `' . self::COLUMN . '` VARCHAR(40) '
            . 'GENERATED ALWAYS AS ('
            . "CASE WHEN `is_active` = 1 THEN CONCAT(COALESCE(`site_id`, 0), '-', `holiday_date`) END"
            . ') STORED'
        );

        DB::statement(
            'ALTER TABLE `holidays` ADD UNIQUE INDEX `' . self::INDEX . '` (`' . self::COLUMN . '`)'
        );
    }

    public function down()
    {
        if (! Schema::hasColumn('holidays', self::COLUMN)) {
            return;
        }

        // The index lives on the generated column, so dropping the column
        // takes it with it. Dropping it explicitly first keeps the reverse
        // readable and works the same either way.
        DB::statement('ALTER TABLE `holidays` DROP INDEX `' . self::INDEX . '`');
        DB::statement('ALTER TABLE `holidays` DROP COLUMN `' . self::COLUMN . '`');
    }
};
