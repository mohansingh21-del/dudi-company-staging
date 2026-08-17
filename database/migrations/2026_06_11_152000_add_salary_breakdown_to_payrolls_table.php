<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('shift_allowance', 12, 2)->default(0)->after('basic_salary');
            $table->decimal('incentives', 12, 2)->default(0)->after('shift_allowance');
            $table->integer('half_days')->default(0)->after('present_days');
            $table->integer('paid_leave_days')->default(0)->after('leave_days');
            $table->integer('unpaid_leave_days')->default(0)->after('paid_leave_days');
            $table->decimal('leave_deduction', 12, 2)->default(0)->after('other_deduction');
            $table->decimal('penalty_deduction', 12, 2)->default(0)->after('leave_deduction');
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
            $table->dropColumn([
                'shift_allowance',
                'incentives',
                'half_days',
                'paid_leave_days',
                'unpaid_leave_days',
                'leave_deduction',
                'penalty_deduction',
            ]);
        });
    }
};
