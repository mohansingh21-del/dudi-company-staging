<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores master: the outside stores this mine draws spare parts from.
 *
 * The mine's own stock lives in `inventories` (one row per product, no
 * location dimension). This table opens the second axis — every other store
 * whose parts end up on a service record — and `store_products` carries the
 * stock those stores hold.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('stores');
    }
};
