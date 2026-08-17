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
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id(); 
            $table->string('employee_code'); 
            $table->date('attendance_date'); 
            $table->dateTime('check_in')->nullable(); 
            $table->dateTime('check_out')->nullable(); 
            $table->string('source_file')->nullable(); 
            $table->string('upload_batch')->nullable(); 
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
        Schema::dropIfExists('attendance_logs');
    }
};
