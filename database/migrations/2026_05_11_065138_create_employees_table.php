<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('employees', function (Blueprint $table) {
           $table->id(); 
           $table->string('employee_code')->unique(); 
           $table->string('name'); 
           $table->string('father_name')->nullable(); 
           $table->date('dob')->nullable(); 
           $table->enum('gender', ['male', 'female', 'other'])->nullable(); 
           $table->string('mobile')->nullable(); 
           $table->text('address')->nullable(); 
           $table->string('emergency_contact')->nullable();
           $table->date('joining_date'); 
           $table->enum('employee_type', [ 'permanent', 'daily_wage' ])->default('permanent');
           $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete(); 
           $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete(); 
           $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
           $table->foreignId('supervisor_id')->nullable()->references('id')->on('employees')->nullOnDelete(); 
           $table->enum('salary_type', [ 'monthly', 'daily_wage' ])->default('monthly');
           $table->decimal('basic_salary', 12, 2)->default(0);
           $table->decimal('daily_wage', 12, 2)->default(0);
           $table->boolean('pf_applicable')->default(false); 
           $table->string('pf_number')->nullable();
           $table->string('bank_name')->nullable(); 
           $table->string('bank_account_number')->nullable();
           $table->string('ifsc_code')->nullable();
           $table->boolean('mess_deduction_applicable')->default(false);
           $table->enum('status', [ 'active', 'inactive', 'resigned' ])->default('active');
           $table->enum('relay_shift', ['general','relay_1','relay_2','relay_3'])->default('general');

           $table->decimal('pf_amount', 10, 2)->default(0);

           $table->decimal('mess_deduction_amount', 10, 2)->default(0);

           $table->integer('rest_days')->default(0);
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
        Schema::dropIfExists('employees');
    }
};
