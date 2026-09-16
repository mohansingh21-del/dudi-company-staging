<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second inventory: what each outside store holds.
 *
 * Deliberately a separate table from `inventories` rather than a store_id
 * column on it — `inventories.product_id` is UNIQUE and the whole own-stock
 * path (InventoryStockService, InventoryImport, the inventory controller)
 * depends on that one-row-per-product shape.
 *
 * `quantity` / `left_quantity` mirror `inventories` exactly so both
 * inventories read the same way. `threshold` is the per-store equivalent of
 * `products.min_stock` — a hard floor a deduction may not cross — and the two
 * are independent: a product can sit at min_stock 5 in the mine's own store
 * and threshold 2 at an outside one.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 15, 2)->default(0.00);
            $table->decimal('left_quantity', 15, 2)->default(0.00);
            $table->decimal('threshold', 15, 2)->default(0.00);
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();

            $table->unique(['store_id', 'product_id']);
            $table->index('product_id');

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('store_products');
    }
};
