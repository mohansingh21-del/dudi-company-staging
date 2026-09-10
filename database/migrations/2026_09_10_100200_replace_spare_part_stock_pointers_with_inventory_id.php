<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One stock pointer instead of two.
 *
 * A spare part used to point either at `products` (own stock, via
 * inventory_product_id) or at `store_products` (an outside store, via
 * store_product_id), with `source` saying which. Now that both inventories are
 * one table, all three collapse into `inventory_id`.
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
        Schema::table('service_spare_parts', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_id')->nullable()->after('service_record_id');
            $table->foreign('inventory_id')->references('id')->on('inventories')->nullOnDelete();
        });

        Schema::table('service_spare_parts', function (Blueprint $table) {
            $table->dropForeign(['inventory_product_id']);
            $table->dropForeign(['store_product_id']);
            $table->dropColumn(['inventory_product_id', 'store_product_id', 'source']);
        });
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

        Schema::table('service_spare_parts', function (Blueprint $table) {
            $table->dropForeign(['inventory_id']);
            $table->dropColumn('inventory_id');
        });
    }
};
