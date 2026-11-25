<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opens the store dimension on the mine's own stock table, folding
 * `store_products` back into it.
 *
 * `inventories` was one row per product with no location; every row now belongs
 * to a store, so the UNIQUE on product_id becomes UNIQUE(store_id, product_id)
 * and the same product can be stocked at several stores.
 *
 * `is_active` comes across from store_products so a mapping can be switched off
 * without deleting its history. The per-store `threshold` deliberately does not:
 * products.min_stock is now the floor in every store.
 *
 * On stock that predates the store dimension: an earlier version of this
 * migration assumed the table was empty and added store_id NOT NULL with no
 * backfill. Where it was not empty that left every existing row at store_id 0,
 * which no store owns, and the foreign key was then refused (errno 1452) — the
 * columns were added, the migration aborted, and it was never recorded, so
 * re-running it died on the duplicate column instead. Rows are now given a
 * store before the constraint goes on, and each step checks whether it is
 * already in place, so a database left in that half-applied state heals.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('inventories', 'store_id')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->unsignedBigInteger('store_id')->after('id');
            });
        }

        if (! Schema::hasColumn('inventories', 'is_active')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->tinyInteger('is_active')->default(1)->after('left_quantity');
            });
        }

        $this->giveStocklessRowsAStore();

        if (! $this->hasForeignKey('inventories', 'inventories_store_id_foreign')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            });
        }

        // MySQL keeps the product_id foreign key on the unique index it was
        // given, and refuses to drop an index a constraint still needs, so the
        // constraint comes off first and goes back on afterwards.
        if ($this->hasIndex('inventories', 'inventories_product_id_unique')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
                $table->dropUnique('inventories_product_id_unique');
            });
        }

        if (! $this->hasIndex('inventories', 'inventories_store_id_product_id_unique')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->unique(['store_id', 'product_id']);
            });
        }

        if (! $this->hasIndex('inventories', 'inventories_product_id_index')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->index('product_id');
            });
        }

        if (! $this->hasForeignKey('inventories', 'inventories_product_id_foreign')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::table('inventories', function (Blueprint $table) {
            // Both constraints come off before the indexes they sit on: store_id
            // is the leading column of the composite unique, so its foreign key
            // is holding that index up and MySQL will not drop it out from under
            // the constraint (errno 1553).
            $table->dropForeign(['store_id']);
            $table->dropForeign(['product_id']);

            $table->dropUnique(['store_id', 'product_id']);
            $table->dropIndex(['product_id']);

            $table->dropColumn(['store_id', 'is_active']);
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->unique('product_id');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Point any row whose store_id names no store at a real one, so the foreign
     * key can go on. Nothing happens on a database where the table was in fact
     * empty, which is why no store is conjured up there.
     */
    private function giveStocklessRowsAStore()
    {
        if ($this->rowsWithoutAStore() === 0) {
            return;
        }

        DB::update(
            'UPDATE `inventories` SET `store_id` = ? WHERE `store_id` NOT IN (SELECT `id` FROM `stores`)',
            [$this->storeForRowsThatPredateStores()]
        );
    }

    private function rowsWithoutAStore()
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM `inventories` WHERE `store_id` NOT IN (SELECT `id` FROM `stores`)'
        )->c;
    }

    /**
     * The store stock carries when it was recorded before stores existed: the
     * first store already on the master, or one created here when there is none
     * to choose. Named rather than left implicit so it can be renamed, merged or
     * emptied like any other store.
     */
    private function storeForRowsThatPredateStores()
    {
        $existing = DB::table('stores')->orderBy('id')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('stores')->insertGetId([
            'name' => 'Main Store',
            'description' => 'Holds the stock that was recorded before stores existed.',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hasIndex($table, $index)
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }

    private function hasForeignKey($table, $constraint)
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ? LIMIT 1',
            [$table, $constraint, 'FOREIGN KEY']
        ) !== null;
    }
};
