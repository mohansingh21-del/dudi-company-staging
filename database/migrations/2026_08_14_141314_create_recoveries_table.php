<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRecoveriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('recoveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recovery_upload_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('employee_id')
                ->constrained('employees');

            $table->string('employee_code');
            $table->string('employee_name');

            $table->enum('recovery_type', [
                'damage',
                'loss',
                'fine',
                'advance',
                'loans'
            ]);

            $table->text('particulars')->nullable();

            $table->date('damage_loss_date')->nullable();

            $table->decimal('amount', 12, 2);

            $table->boolean('show_cause_issued')->default(false);

            $table->string('explanation_witness')->nullable();

            $table->unsignedInteger('number_of_installments')->nullable();

            $table->string('first_month_year')->nullable();
            $table->string('last_month_year')->nullable();

            $table->date('complete_recovery_date')->nullable();

            $table->text('remarks')->nullable();

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
        Schema::dropIfExists('recoveries');
    }
}
