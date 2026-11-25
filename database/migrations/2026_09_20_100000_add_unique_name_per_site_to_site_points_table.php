<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddUniqueNamePerSiteToSitePointsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->renameExistingDuplicates();

        Schema::table('site_points', function (Blueprint $table) {
            $table->unique(['site_id', 'name'], 'site_points_site_id_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('site_points', function (Blueprint $table) {
            $table->dropUnique('site_points_site_id_name_unique');
        });
    }

    /**
     * Existing rows may already hold duplicates, which would make the unique
     * index impossible to add. Keep the oldest row of each group as-is and
     * suffix the rest so no data is lost.
     *
     * @return void
     */
    protected function renameExistingDuplicates()
    {
        $groups = DB::table('site_points')
            ->select('site_id', 'name')
            ->groupBy('site_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $ids = DB::table('site_points')
                ->where('site_id', $group->site_id)
                ->where('name', $group->name)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1);

            $suffix = 2;

            foreach ($ids as $id) {
                do {
                    $candidate = $this->suffixedName($group->name, $suffix);
                    $suffix++;
                } while (DB::table('site_points')
                    ->where('site_id', $group->site_id)
                    ->where('name', $candidate)
                    ->exists());

                DB::table('site_points')
                    ->where('id', $id)
                    ->update(['name' => $candidate]);
            }
        }
    }

    /**
     * Build "name (n)", trimming the base so it still fits the 150 char column.
     *
     * @param  string  $name
     * @param  int  $suffix
     * @return string
     */
    protected function suffixedName($name, $suffix)
    {
        $tail = ' (' . $suffix . ')';
        $base = mb_substr($name, 0, 150 - mb_strlen($tail));

        return $base . $tail;
    }
}
