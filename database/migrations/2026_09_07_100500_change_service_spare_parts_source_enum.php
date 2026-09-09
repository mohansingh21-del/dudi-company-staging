<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * source: 'inventory'|'vendor' becomes 'inventory'|'store'.
 *
 * The old 'vendor' branch was free text — a part_name and a vendor_name, no
 * product link and no stock movement. Outside parts are now stock-tracked
 * against a store, so the flag says which of the two inventories a part came
 * from and nothing else.
 *
 * Widen, backfill, narrow — the middle state exists only so the UPDATE has a
 * legal value to write. Rows migrated from 'vendor' keep their part_name,
 * vendor_name and amount but have no store_product_id: they predate stores and
 * cannot be attributed to one. Everything written from here on must have it.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE service_spare_parts MODIFY COLUMN source ENUM('inventory', 'vendor', 'store') NOT NULL DEFAULT 'inventory'");
        DB::table('service_spare_parts')->where('source', 'vendor')->update(['source' => 'store']);
        DB::statement("ALTER TABLE service_spare_parts MODIFY COLUMN source ENUM('inventory', 'store') NOT NULL DEFAULT 'inventory'");
    }

    public function down()
    {
        DB::statement("ALTER TABLE service_spare_parts MODIFY COLUMN source ENUM('inventory', 'vendor', 'store') NOT NULL DEFAULT 'inventory'");
        DB::table('service_spare_parts')->where('source', 'store')->update(['source' => 'vendor']);
        DB::statement("ALTER TABLE service_spare_parts MODIFY COLUMN source ENUM('inventory', 'vendor') NOT NULL DEFAULT 'inventory'");
    }
};
