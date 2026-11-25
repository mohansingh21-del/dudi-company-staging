<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second inventory is gone: `inventories` now carries store_id and holds
 * what this table used to.
 *
 * Runs after the spare-parts migration, which drops the only foreign key
 * pointing here. Balances are not carried over — the merge was made while this
 * table held test data only.
 *
 * down() rebuilds the shape but not the rows.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::dropIfExists('store_products');
    }

    public function down()
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
};
