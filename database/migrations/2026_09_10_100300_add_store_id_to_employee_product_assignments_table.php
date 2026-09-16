<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock issued to an employee now comes out of a named store.
 *
 * Without this column the allocation history on an inventory row would show
 * every store's issues of that product, not the ones that came off this row.
 *
 * store_id is required from here on. Issues recorded before stores existed are
 * given the same store the stock they came out of was given, for the reason set
 * out in the inventories migration: a NOT NULL column added to rows that are
 * already there starts at 0, and no store owns 0, so the foreign key would be
 * refused.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('employee_product_assignments', 'store_id')) {
            Schema::table('employee_product_assignments', function (Blueprint $table) {
                $table->unsignedBigInteger('store_id')->after('product_id');
            });
        }

        $this->giveStorelessIssuesAStore();

        if (! $this->hasForeignKey('employee_product_assignments', 'employee_product_assignments_store_id_foreign')) {
            Schema::table('employee_product_assignments', function (Blueprint $table) {
                $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::table('employee_product_assignments', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }

    private function giveStorelessIssuesAStore()
    {
        $storeless = (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM `employee_product_assignments`
             WHERE `store_id` NOT IN (SELECT `id` FROM `stores`)'
        )->c;

        if ($storeless === 0) {
            return;
        }

        // The store holding this product's oldest stock, which for anything
        // recorded before stores existed is where inventories put it.
        DB::statement(
            'UPDATE `employee_product_assignments` AS a
             JOIN (SELECT `product_id`, MIN(`id`) AS `id` FROM `inventories` GROUP BY `product_id`) AS i
               ON i.`product_id` = a.`product_id`
             JOIN `inventories` AS s ON s.`id` = i.`id`
             SET a.`store_id` = s.`store_id`
             WHERE a.`store_id` NOT IN (SELECT `id` FROM `stores`)'
        );

        // An issue of a product nothing stocks any more has no such store to
        // follow, so it falls back the same way inventories does.
        DB::update(
            'UPDATE `employee_product_assignments` SET `store_id` = ?
             WHERE `store_id` NOT IN (SELECT `id` FROM `stores`)',
            [$this->storeForIssuesThatPredateStores()]
        );
    }

    private function storeForIssuesThatPredateStores()
    {
        $existing = DB::table('stores')->orderBy('id')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('stores')->insertGetId([
            'name' => 'Main Store',
            'description' => 'Holds the stock that was recorded before stores existed.',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hasForeignKey($table, $constraint)
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$table, $constraint, 'FOREIGN KEY']
        ) !== null;
    }
};
