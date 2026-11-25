<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * The regular monthly deduction for this recovery, worked out from
     * the 25% cap when the record is created.
     *
     * Stored rather than derived because deriving it means projecting a
     * whole schedule per row, and the register lists twenty at a time.
     *
     * It is a display figure only. The authoritative per-month amounts
     * live in loan_recovery_installments once payroll runs, and the
     * final month is nearly always smaller than this — it collects
     * whatever balance is left.
     */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->decimal('installment_amount', 12, 2)
                ->nullable()
                ->after('number_of_installments');
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropColumn('installment_amount');
        });
    }
};
