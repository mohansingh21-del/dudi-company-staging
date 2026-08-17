<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_recovery_installments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('penalty_id');

            $table->unsignedBigInteger('employee_id');

            /*
             * Payroll period for this installment
             */
            $table->unsignedTinyInteger('month');

            $table->unsignedSmallInteger('year');

            /*
             * 1, 2, 3...
             */
            $table->unsignedInteger('installment_number');

            /*
             * Loan amount before this installment
             */
            $table->decimal('opening_balance', 12, 2);

            /*
             * Salary used to determine 25% limit
             */
            $table->decimal('salary_basis', 12, 2);

            /*
             * 25% of salary
             */
            $table->decimal('maximum_allowed_amount', 12, 2);

            /*
             * Actual amount deducted
             */
            $table->decimal('installment_amount', 12, 2);

            /*
             * Loan amount remaining after installment
             */
            $table->decimal('closing_balance', 12, 2);

            /*
             * pending / deducted / skipped
             */
            $table->string('status', 20)
                ->default('pending');

            /*
             * Link to payroll once payroll is generated
             */
            $table->unsignedBigInteger('payroll_id')
                ->nullable();

            $table->date('deduction_date')
                ->nullable();

            $table->text('remarks')
                ->nullable();

            $table->timestamps();

            $table->foreign('penalty_id')
                ->references('id')
                ->on('penalties')
                ->cascadeOnDelete();

            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->cascadeOnDelete();

            $table->foreign('payroll_id')
                ->references('id')
                ->on('payrolls')
                ->nullOnDelete();

            /*
             * One installment for one loan in one payroll month.
             */
            $table->unique([
                'penalty_id',
                'month',
                'year',
            ], 'loan_installment_period_unique');

            $table->index([
                'employee_id',
                'month',
                'year',
            ]);

            $table->index([
                'penalty_id',
                'installment_number',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_recovery_installments');
    }
};
