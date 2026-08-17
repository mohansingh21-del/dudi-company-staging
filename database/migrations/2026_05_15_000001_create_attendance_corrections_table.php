<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAttendanceCorrectionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->onDelete('cascade');
            $table->date('date');
            $table->time('login_time')->nullable();
            $table->time('logout_time')->nullable();
            $table->enum('is_approved',['0','1','2','3','4'])->default('1')->comment="Requested status: 1=present, 3=half day, etc";
            $table->longText('remarks')->nullable();
            $table->enum('status',['0','1','2'])->default('0')->comment="0=pending, 1=approved, 2=rejected";
            $table->longText('admin_comments')->nullable();
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
        Schema::dropIfExists('attendance_corrections');
    }
}
