<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 1. Resolve weeks where more than one relay claims the same shift.
        //    The 2026_07_17_090000 cleanup only compared rotating relays, so a
        //    collision between a rotating and a non-rotating relay survived it.
        $duplicateGroups = DB::table('relay_shift_mappings')
            ->select('week_start_date', 'shift_id', DB::raw('count(*) as cnt'))
            ->groupBy('week_start_date', 'shift_id')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($duplicateGroups as $group) {
            $mappings = DB::table('relay_shift_mappings as m')
                ->leftJoin('relays as r', 'r.id', '=', 'm.relay_id')
                ->where('m.week_start_date', $group->week_start_date)
                ->where('m.shift_id', $group->shift_id)
                // Keep the mapping belonging to the live rotating relay; an
                // inactive or pinned relay is the one that loses the shift.
                ->orderByDesc('r.is_active')
                ->orderByDesc('r.is_rotating')
                ->orderBy('m.id')
                ->pluck('m.id')
                ->toArray();

            array_shift($mappings);
            DB::table('relay_shift_mappings')->whereIn('id', $mappings)->delete();
        }

        // 2. A shift may now be held by at most one relay in any given week.
        Schema::table('relay_shift_mappings', function (Blueprint $table) {
            $table->unique(['week_start_date', 'shift_id'], 'unique_week_shift');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('relay_shift_mappings', function (Blueprint $table) {
            $table->dropUnique('unique_week_shift');
        });
    }
};
