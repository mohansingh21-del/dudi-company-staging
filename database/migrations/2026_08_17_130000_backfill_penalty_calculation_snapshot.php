<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Penalties created before the recovery fields were added have an
     * empty calculation snapshot. Payroll reads the snapshot, so those
     * rows would deduct 0 until they are backfilled.
     *
     * For a pre-existing penalty the current values ARE the original
     * values, so copying them across is the correct snapshot.
     */
    public function up(): void
    {
        /*
         * Ordering puts add_recovery_fields_to_penalties_table first, but
         * running migrations one at a time with --path can defeat that.
         * Stop with something readable rather than an SQL error, and stop
         * before being recorded as run — otherwise the backfill is skipped
         * for good once the column does appear.
         */
        if (!Schema::hasColumn('penalties', 'calculation_amount')) {
            throw new RuntimeException(
                'penalties.calculation_amount does not exist yet. Run '
                . '2026_08_13_153145_add_recovery_fields_to_penalties_table '
                . 'first, or just run "php artisan migrate" with no --path '
                . 'so the order is handled for you.'
            );
        }

        DB::table('penalties')
            ->whereNull('calculation_amount')
            ->update([
                'calculation_amount' => DB::raw('amount'),
                'calculation_recovery_type' => DB::raw('recovery_type'),
                'calculation_particulars' => DB::raw('particulars'),
                'calculation_date' => DB::raw('penalty_date'),
                'calculation_number_of_installments' => DB::raw('number_of_installments'),
                'calculation_first_month' => DB::raw('first_month'),
                'calculation_first_year' => DB::raw('first_year'),
                'calculation_last_month' => DB::raw('last_month'),
                'calculation_last_year' => DB::raw('last_year'),
            ]);
    }

    /*
     * Deliberately not reversible.
     *
     * Nulling the snapshot again would not distinguish rows filled by
     * this migration from rows written normally by PenaltyController,
     * so a rollback would destroy real payroll data.
     */
    public function down(): void
    {
        //
    }
};
