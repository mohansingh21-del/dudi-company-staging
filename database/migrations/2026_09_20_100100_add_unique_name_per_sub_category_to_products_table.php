<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddUniqueNamePerSubCategoryToProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->renameExistingDuplicates();

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_name_unique');
            $table->unique(['sub_category_id', 'name'], 'products_sub_category_id_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->renameDuplicateNames();

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_sub_category_id_name_unique');
            $table->unique('name', 'products_name_unique');
        });
    }

    /**
     * The name column was globally unique until now, so no duplicate pair can
     * exist yet. This still runs defensively in case the index was dropped by
     * hand: keep the oldest row of each group as-is and suffix the rest so no
     * data is lost.
     *
     * @return void
     */
    protected function renameExistingDuplicates()
    {
        $groups = DB::table('products')
            ->select('sub_category_id', 'name')
            ->groupBy('sub_category_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $ids = DB::table('products')
                ->where('sub_category_id', $group->sub_category_id)
                ->where('name', $group->name)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1);

            $suffix = 2;

            foreach ($ids as $id) {
                do {
                    $candidate = $this->suffixedName($group->name, $suffix);
                    $suffix++;
                } while (DB::table('products')
                    ->where('sub_category_id', $group->sub_category_id)
                    ->where('name', $candidate)
                    ->exists());

                DB::table('products')
                    ->where('id', $id)
                    ->update(['name' => $candidate]);
            }
        }
    }

    /**
     * Rolling back restores the globally unique name, which the rows added
     * under the per-subcategory rule may violate. Keep the oldest row of each
     * name as-is and suffix the rest.
     *
     * @return void
     */
    protected function renameDuplicateNames()
    {
        $names = DB::table('products')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        foreach ($names as $name) {
            $ids = DB::table('products')
                ->where('name', $name)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1);

            $suffix = 2;

            foreach ($ids as $id) {
                do {
                    $candidate = $this->suffixedName($name, $suffix);
                    $suffix++;
                } while (DB::table('products')->where('name', $candidate)->exists());

                DB::table('products')
                    ->where('id', $id)
                    ->update(['name' => $candidate]);
            }
        }
    }

    /**
     * Build "name (n)", trimming the base so it still fits the 255 char column.
     *
     * @param  string  $name
     * @param  int  $suffix
     * @return string
     */
    protected function suffixedName($name, $suffix)
    {
        $tail = ' (' . $suffix . ')';
        $base = mb_substr($name, 0, 255 - mb_strlen($tail));

        return $base . $tail;
    }
}
