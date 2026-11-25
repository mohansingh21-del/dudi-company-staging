<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every inventory alert now opens the inventory module filtered to what it is
 * about, so an alert that cannot must not exist.
 *
 * - product_removed and bulk_import are gone as types: the first points at a
 *   stock row that no longer exists, the second at no product at all.
 * - An alert whose product, store or stock row is deleted would open an empty
 *   screen, so those foreign keys now cascade instead of setting NULL.
 *   inventory_id gains the foreign key it deliberately lacked, which only
 *   product_removed needed.
 *
 * down() restores the keys but not the deleted rows.
 */
return new class extends Migration
{
    public function up()
    {
        DB::table('inventory_alerts')->whereIn('type', ['product_removed', 'bulk_import'])->delete();

        DB::table('inventory_alerts')->whereNull('product_id')->delete();

        // Stock-row alerts also need their store and a stock row that still
        // exists. min_stock_changed is product-wide and needs neither.
        DB::table('inventory_alerts')
            ->where('type', '!=', 'min_stock_changed')
            ->where(function ($query) {
                $query->whereNull('store_id')
                    ->orWhereNull('inventory_id')
                    ->orWhereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('inventories')
                            ->whereColumn('inventories.id', 'inventory_alerts.inventory_id');
                    });
            })
            ->delete();

        Schema::table('inventory_alerts', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropForeign(['product_id']);
        });

        Schema::table('inventory_alerts', function (Blueprint $table) {
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('inventory_id')->references('id')->on('inventories')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::table('inventory_alerts', function (Blueprint $table) {
            $table->dropForeign(['inventory_id']);
            $table->dropForeign(['store_id']);
            $table->dropForeign(['product_id']);
        });

        Schema::table('inventory_alerts', function (Blueprint $table) {
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }
};
