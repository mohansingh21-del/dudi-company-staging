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
        Schema::dropIfExists('employee_payrolls');
        Schema::create('employee_payrolls', function (Blueprint $table) {

            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();

            $table->enum('salary_type', ['monthly', 'daily_wage'])
                ->default('monthly');

            $table->decimal('basic_salary', 12, 2)->default(0);

            $table->decimal('daily_wage', 12, 2)->default(0);

            $table->boolean('pf_applicable')->default(false);

            $table->string('pf_number')->nullable();

            $table->string('bank_name')->nullable();

            $table->string('bank_account_number')->nullable();

            $table->string('ifsc_code')->nullable();

            $table->boolean('mess_deduction_applicable')
                ->default(false);

            $table->boolean('other_deduction_appliacble')
                ->default(false);

            $table->decimal('other_deduction', 10, 2)
                ->default(0);

            $table->integer('rest_days')->default(0);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('employee_payrolls');
    }
};
