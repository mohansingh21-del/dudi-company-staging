<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One stock pointer instead of two.
 *
 * A spare part used to point either at `products` (own stock, via
 * inventory_product_id) or at `store_products` (an outside store, via
 * store_product_id), with `source` saying which. Now that both inventories are
 * one table, all three collapse into `inventory_id`.
 *
 * The own-stock pointer is carried across rather than dropped: it named a
 * product, and that product's stock is now a row in `inventories`, so the row
 * it becomes is the one this part came out of. Where a product is stocked at
 * more than one store the lowest inventory id wins, which is the store the
 * previous migration gave stock that predates stores.
 *
 * It stays nullable for two reasons: the foreign key is ON DELETE SET NULL, and
 * rows written against the old store_products table have nothing left to point
 * at once it is dropped. Those keep the part_name / vendor_name snapshot they
 * were always stored with, which is what the report reads anyway.
 *
 * `source` goes because with a single table it no longer distinguishes
 * anything — every part now comes out of some store.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('service_spare_parts', 'inventory_id')) {
            Schema::table('service_spare_parts', function (Blueprint $table) {
                $table->unsignedBigInteger('inventory_id')->nullable()->after('service_record_id');
            });
        }

        if (! $this->hasForeignKey('service_spare_parts', 'service_spare_parts_inventory_id_foreign')) {
            Schema::table('service_spare_parts', function (Blueprint $table) {
                $table->foreign('inventory_id')->references('id')->on('inventories')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('service_spare_parts', 'inventory_product_id')) {
            DB::statement(
                'UPDATE `service_spare_parts` AS p
                 JOIN (SELECT `product_id`, MIN(`id`) AS `id` FROM `inventories` GROUP BY `product_id`) AS i
                   ON i.`product_id` = p.`inventory_product_id`
                 SET p.`inventory_id` = i.`id`
                 WHERE p.`inventory_id` IS NULL AND p.`inventory_product_id` IS NOT NULL'
            );
        }

        foreach (['inventory_product_id', 'store_product_id'] as $column) {
            $constraint = 'service_spare_parts_' . $column . '_foreign';

            if ($this->hasForeignKey('service_spare_parts', $constraint)) {
                Schema::table('service_spare_parts', function (Blueprint $table) use ($column) {
                    $table->dropForeign([$column]);
                });
            }
        }

        $spent = array_values(array_filter(
            ['inventory_product_id', 'store_product_id', 'source'],
            function ($column) {
                return Schema::hasColumn('service_spare_parts', $column);
            }
        ));

        if ($spent !== []) {
            Schema::table('service_spare_parts', function (Blueprint $table) use ($spent) {
                $table->dropColumn($spent);
            });
        }
    }

    public function down()
    {
        Schema::table('service_spare_parts', function (Blueprint $table) {
            $table->enum('source', ['inventory', 'store'])->default('inventory')->after('service_record_id');
            $table->unsignedBigInteger('inventory_product_id')->nullable()->after('source');
            $table->unsignedBigInteger('store_product_id')->nullable()->after('inventory_product_id');

            $table->foreign('inventory_product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('store_product_id')->references('id')->on('store_products')->nullOnDelete();
        });

        // Send the pointer back the way it came, to the product the inventory
        // row stocks. Which store it was is not recoverable, so `source` keeps
        // the default every row is given above.
        DB::statement(
            'UPDATE `service_spare_parts` AS p
             JOIN `inventories` AS i ON i.`id` = p.`inventory_id`
             SET p.`inventory_product_id` = i.`product_id`
             WHERE p.`inventory_id` IS NOT NULL'
        );

        Schema::table('service_spare_parts', function (Blueprint $table) {
            $table->dropForeign(['inventory_id']);
            $table->dropColumn('inventory_id');
        });
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
