<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecoveryUploadRowsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('recovery_upload_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recovery_upload_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('employee_code');
            $table->string('name');

            $table->string('recovery_type')->nullable();
            $table->text('particulars')->nullable();

            $table->date('damage_loss_date')->nullable();

            $table->decimal('amount', 12, 2)->nullable();

            $table->string('show_cause_issued')->nullable();
            $table->string('explanation_witness')->nullable();

            $table->unsignedInteger('number_of_installments')->nullable();

            $table->string('first_month_year')->nullable();
            $table->string('last_month_year')->nullable();

            $table->date('complete_recovery_date')->nullable();

            $table->text('remarks')->nullable();

            /*
     * Stores all validation errors.
     *
     * Example:
     * {
     *   "employee_code": ["Employee does not exist"],
     *   "name": ["Name does not match employee"]
     * }
     */
            $table->json('errors')->nullable();

            $table->boolean('is_valid')->default(false);

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
        Schema::dropIfExists('recovery_upload_rows');
    }
}
