<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 1. Delete mappings that don't start on a Monday (database-agnostic)
        $records = DB::table('relay_shift_mappings')->get();
        foreach ($records as $record) {
            $date = \Carbon\Carbon::parse($record->week_start_date);
            if ($date->dayOfWeek !== \Carbon\Carbon::MONDAY) {
                DB::table('relay_shift_mappings')->where('id', $record->id)->delete();
            }
        }

        // 2. Resolve duplicates where different rotating relays share the same shift in the same week
        $weeks = DB::table('relay_shift_mappings')
            ->select('week_start_date')
            ->groupBy('week_start_date')
            ->get();

        foreach ($weeks as $w) {
            $mappings = DB::table('relay_shift_mappings')
                ->where('week_start_date', $w->week_start_date)
                ->get();

            $shiftCounts = [];
            foreach ($mappings as $m) {
                $isRotating = DB::table('relays')->where('id', $m->relay_id)->value('is_rotating');
                if ($isRotating) {
                    $shiftCounts[$m->shift_id][] = $m->id;
                }
            }

            foreach ($shiftCounts as $shiftId => $ids) {
                if (count($ids) > 1) {
                    // Keep the first mapping, delete any duplicates
                    array_shift($ids);
                    DB::table('relay_shift_mappings')->whereIn('id', $ids)->delete();
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // No reverse operation needed
    }
};
