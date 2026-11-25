<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One movement log for both inventories.
 *
 * NULL store_id means the mine's own inventory, which is what every row
 * written before this migration was — so the existing history stays correct
 * without a backfill. Queries that want own-stock movements only must now say
 * whereNull('store_id').
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->after('product_id');
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
