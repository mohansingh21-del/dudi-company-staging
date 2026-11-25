<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Penalties raised before the schedule was computed at creation
     * have no installment plan on them, so the register shows blanks
     * where it should show "1 x 500, Jun 2026".
     *
     * Only rows the employee can clear inside a single month are
     * filled: for those the plan is unambiguous — one installment, in
     * the penalty's own month, for the whole amount. Anything larger
     * would need a projection against the salary of the month it was
     * raised, which is not reliably reconstructable after the fact, so
     * those are left blank rather than guessed at.
     *
     * The calculation_* snapshot is deliberately untouched. Payroll
     * already falls back to the penalty's own month when
     * calculation_first_month is null, so behaviour is unchanged, and
     * backfilling an audit record that is supposed to be written once
     * at creation would misrepresent when these values were decided.
     */
    public function up(): void
    {
        /*
         * Same guard as the snapshot backfill: fail readably, and fail
         * before being recorded, if the columns this depends on have not
         * been added yet.
         */
        foreach (['installment_amount', 'calculation_amount'] as $column) {
            if (!Schema::hasColumn('penalties', $column)) {
                throw new RuntimeException(
                    "penalties.{$column} does not exist yet. Run the "
                    . 'add_recovery_fields_to_penalties_table and '
                    . 'add_installment_amount_to_penalties_table migrations '
                    . 'first, or just run "php artisan migrate" with no '
                    . '--path so the order is handled for you.'
                );
            }
        }

        $pending = DB::table('penalties')
            ->whereNull('installment_amount')
            ->whereNotNull('calculation_amount')
            ->get();

        foreach ($pending as $penalty) {
            /*
             * Same row Employee::activePayroll() resolves to: the most
             * recent active one, since an employee may carry several.
             */
            $basicSalary = (float) DB::table('employee_payrolls')
                ->where('employee_id', $penalty->employee_id)
                ->where('is_active', true)
                ->orderByDesc('id')
                ->value('basic_salary');

            // Not clearable in one month, or no salary on record to
            // judge against — leave it for the API to compute.
            if ($basicSalary <= 0
                || (float) $penalty->calculation_amount > $basicSalary * 0.25) {
                continue;
            }

            DB::table('penalties')->where('id', $penalty->id)->update([
                'number_of_installments' => 1,
                'installment_amount' => $penalty->calculation_amount,
                'first_month' => $penalty->month,
                'first_year' => $penalty->year,
                'last_month' => $penalty->month,
                'last_year' => $penalty->year,
                'date_of_complete_recovery' => \Carbon\Carbon::create(
                    $penalty->year,
                    $penalty->month,
                    1
                )->endOfMonth()->toDateString(),
            ]);
        }

        /*
         * 'particulars' became the register's description column, but
         * rows created when 'reason' held that text still have it null,
         * so the register and the export render blank rows.
         */
        DB::table('penalties')
            ->whereNull('particulars')
            ->whereNotNull('reason')
            ->update(['particulars' => DB::raw('reason')]);
    }

    /*
     * Not reversible: nulling these again would also wipe schedules
     * written legitimately at creation, and the two are
     * indistinguishable once stored.
     */
    public function down(): void
    {
        //
    }
};
