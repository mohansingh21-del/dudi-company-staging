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
        Schema::create('attendance_processeds', function (Blueprint $table) {
           $table->id(); 
           $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
           $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
           $table->date('date');
           $table->dateTime('check_in')->nullable();
           $table->dateTime('check_out')->nullable();
           $table->decimal('working_hours', 5, 2)->default(0);
           $table->integer('late_minutes')->default(0);
           $table->integer('early_exit_minutes')->default(0);
           $table->enum('attendance_status', [ 'present', 'absent', 'half_day', 'leave', 'rest_day' ])->default('absent');
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
        Schema::dropIfExists('attendance_processeds');
    }
};
