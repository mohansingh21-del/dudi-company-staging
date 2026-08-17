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
        Schema::create('leaves', function (Blueprint $table) {
           $table->id();
           $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
           $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->nullOnDelete();
           $table->date('from_date');
           $table->date('to_date');
           $table->text('reason')->nullable();
           $table->enum('status', [ 'pending', 'approved', 'rejected' ])->default('pending');
           $table->foreignId('approved_by')->nullable()->references('id')->on('users')->nullOnDelete();
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
        Schema::dropIfExists('leaves');
    }
};
