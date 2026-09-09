<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scheduled training session. The type comes from the existing
     * training_types master, and the supervisor is the employee running the
     * session — the same "Supervisor" employees served by the employees
     * dropdown, not a users row.
     *
     * Enrolled employees live in training_employees; a training is created and
     * enrolled in one transaction.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->id();

            $table->string('training_name');

            $table->unsignedBigInteger('training_type_id');
            $table->unsignedBigInteger('supervisor_id');

            $table->date('start_date');
            $table->date('end_date');

            $table->boolean('is_active')->default(1);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['start_date', 'end_date']);

            // Restrict: a type or supervisor still referenced by a scheduled
            // training must not disappear from under it.
            $table->foreign('training_type_id')
                ->references('id')
                ->on('training_types')
                ->onDelete('restrict');

            $table->foreign('supervisor_id')
                ->references('id')
                ->on('employees')
                ->onDelete('restrict');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('trainings');
    }
};
