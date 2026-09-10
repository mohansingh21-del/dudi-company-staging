<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock issued to an employee now comes out of a named store.
 *
 * Without this column the allocation history on an inventory row would show
 * every store's issues of that product, not the ones that came off this row.
 *
 * NOT NULL with no backfill: the table is empty at the time of this change, and
 * store_id is required to assign from here on.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('employee_product_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->after('product_id');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('employee_product_assignments', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
