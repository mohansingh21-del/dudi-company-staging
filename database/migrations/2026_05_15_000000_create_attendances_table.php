<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAttendancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->date('date');
            $table->time('login_time')->nullable();
            $table->time('logout_time')->nullable();
            $table->longText('login_latitude')->nullable();
            $table->longText('login_longitude')->nullable();
            $table->string('login_location_name')->nullable();
            $table->longText('logout_latitude')->nullable();
            $table->longText('logout_longitude')->nullable();
            $table->string('logout_location_name')->nullable();
            
            $table->longText('description')->nullable();
            $table->enum('is_approved',['0','1','2','3','4'])->default('0')->comment("0=pending, 1=accept(present), 2=reject(abcent), 3=half day, 4=leave");
            $table->longText('comments')->nullable();
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
        Schema::dropIfExists('attendances');
    }
}
