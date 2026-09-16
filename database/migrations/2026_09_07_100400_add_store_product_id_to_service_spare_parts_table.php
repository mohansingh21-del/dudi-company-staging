<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store-side counterpart of inventory_product_id.
 *
 * A part issued from the mine's own stock points at products via
 * inventory_product_id; a part issued from an outside store points at that
 * store's stock row via store_product_id. Exactly one of the two is set.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('service_spare_parts', function (Blueprint $table) {
            $table->unsignedBigInteger('store_product_id')->nullable()->after('inventory_product_id');
            $table->foreign('store_product_id')->references('id')->on('store_products')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('service_spare_parts', function (Blueprint $table) {
            $table->dropForeign(['store_product_id']);
            $table->dropColumn('store_product_id');
        });
    }
};
