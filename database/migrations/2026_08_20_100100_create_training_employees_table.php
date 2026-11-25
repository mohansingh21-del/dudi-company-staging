<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch enrolment for a training. Rows are replaced wholesale on update —
     * the unique key stops the same employee being enrolled twice, and the
     * cascade clears the batch when the training itself is deleted.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('training_employees', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('training_id');
            $table->unsignedBigInteger('employee_id');

            $table->timestamps();

            $table->unique(['training_id', 'employee_id']);

            $table->foreign('training_id')
                ->references('id')
                ->on('trainings')
                ->onDelete('cascade');

            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->onDelete('cascade');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('training_employees');
    }
};
