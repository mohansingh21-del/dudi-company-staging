<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inventory alert feed.
 *
 * Two kinds of row share the table. Level alerts (low_stock, out_of_stock)
 * describe a stock row's current state: at most one of each is open per
 * inventory row, and they are resolved rather than deleted once it recovers.
 * Event alerts (everything else) are one-off facts and never resolve.
 *
 * Product and store names are copied in at write time, so the feed still reads
 * correctly after a product is renamed or a stock row is removed.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->id();

            // low_stock, out_of_stock, back_in_stock, stock_added,
            // stock_replenished, product_removed, bulk_import,
            // min_stock_changed. Not an enum, so adding a type needs no
            // migration.
            $table->string('type', 32);
            $table->string('severity', 16); // critical, warning, info

            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('store_name')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name')->nullable();

            // No foreign key: removing a product from a store deletes its row,
            // and the product_removed alert must keep pointing at what it was.
            $table->unsignedBigInteger('inventory_id')->nullable();

            // The movement that raised the alert, and the row's position right
            // after it.
            $table->decimal('quantity', 15, 2)->nullable();
            $table->decimal('left_quantity', 15, 2)->nullable();
            $table->decimal('min_stock', 15, 2)->nullable();

            // What moved the stock: assignment, service_record,
            // service_record_return, manual_add, bulk_import, product_update,
            // store_unmap.
            $table->string('source', 32)->nullable();
            // Free text naming the thing behind the movement — an employee
            // code, a service ticket.
            $table->string('reference')->nullable();

            $table->string('title');
            $table->text('message');
            $table->json('meta')->nullable();

            $table->unsignedBigInteger('triggered_by')->nullable();

            // Shared read state: one admin reading an alert reads it for all.
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('read_by')->nullable();

            // Level alerts only.
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('set null');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('triggered_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('read_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['type', 'created_at']);
            $table->index(['inventory_id', 'type', 'resolved_at']);
            $table->index(['store_id', 'created_at']);
            $table->index('read_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('inventory_alerts');
    }
};
