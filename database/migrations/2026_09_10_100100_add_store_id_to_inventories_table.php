<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opens the store dimension on the mine's own stock table, folding
 * `store_products` back into it.
 *
 * `inventories` was one row per product with no location; every row now belongs
 * to a store, so the UNIQUE on product_id becomes UNIQUE(store_id, product_id)
 * and the same product can be stocked at several stores. There is no default or
 * "main" store — every store is one the user created, so store_id is NOT NULL
 * with no backfill: the table is empty at the time of this change.
 *
 * `is_active` comes across from store_products so a mapping can be switched off
 * without deleting its history. The per-store `threshold` deliberately does not:
 * products.min_stock is now the floor in every store.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->after('id');
            $table->tinyInteger('is_active')->default(1)->after('left_quantity');

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::table('inventories', function (Blueprint $table) {
            // MySQL keeps the product_id foreign key on the unique index it was
            // given, and refuses to drop an index a constraint still needs, so
            // the constraint comes off first and goes back on afterwards.
            $table->dropForeign(['product_id']);
            $table->dropUnique('inventories_product_id_unique');

            $table->unique(['store_id', 'product_id']);
            $table->index('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropUnique(['store_id', 'product_id']);
            $table->dropIndex(['product_id']);

            $table->dropForeign(['store_id']);
            $table->dropColumn(['store_id', 'is_active']);
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->unique('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }
};
