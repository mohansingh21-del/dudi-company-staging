<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * All pay-related data is sourced from `employee_payrolls`. These columns
     * duplicated it and were never populated — verified before dropping:
     * 0 of 31 employees had a bank account number or a non-zero basic salary.
     *
     * `employee_type` is deliberately kept: it is the contractual relationship
     * (Employee Register col 13), not a pay setting.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'salary_type',
                'basic_salary',
                'daily_wage',
                'pf_applicable',
                'pf_number',
                'pf_amount',
                'mess_deduction_applicable',
                'mess_deduction_amount',
                'other_deduction_appliacble',
                'other_deduction',
                'bank_name',
                'bank_account_number',
                'ifsc_code',
                'rest_days',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restores the column definitions only. The data lived in
     * `employee_payrolls` and is not copied back.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('salary_type', ['monthly', 'daily_wage'])->default('monthly')->after('supervisor_id');
            $table->decimal('basic_salary', 12, 2)->default(0)->after('salary_type');
            $table->decimal('daily_wage', 12, 2)->default(0)->after('basic_salary');
            $table->boolean('pf_applicable')->default(false)->after('daily_wage');
            $table->string('pf_number')->nullable()->after('pf_applicable');
            $table->string('bank_name')->nullable()->after('pf_number');
            $table->string('bank_account_number')->nullable()->after('bank_name');
            $table->string('ifsc_code')->nullable()->after('bank_account_number');
            $table->boolean('mess_deduction_applicable')->default(false)->after('ifsc_code');
            $table->boolean('other_deduction_appliacble')->default(false)->after('mess_deduction_applicable');
            $table->decimal('other_deduction', 10, 2)->default(0.00)->after('mess_deduction_applicable');
            $table->decimal('pf_amount', 10, 2)->default(0)->after('is_active');
            $table->decimal('mess_deduction_amount', 10, 2)->default(0)->after('pf_amount');
            $table->integer('rest_days')->default(0)->after('mess_deduction_amount');
        });
    }
};
