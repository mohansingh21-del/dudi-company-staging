<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Overtime was earned but never paid: the hours exist in attendance and the
     * rate exists in the wage master, but payroll had nowhere to put either, so
     * it was silently dropped from gross and net.
     *
     * Both are stored rather than derived on read, for the same reason the rest
     * of the payroll row is — a generated payroll must keep showing what was
     * actually paid even after a shift or a rate is later corrected.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('overtime_hours', 8, 2)->default(0)->after('incentives');
            $table->decimal('overtime_payment', 12, 2)->default(0)->after('overtime_hours');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['overtime_hours', 'overtime_payment']);
        });
    }
};
