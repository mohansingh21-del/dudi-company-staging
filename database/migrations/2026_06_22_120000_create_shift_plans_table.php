<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShiftPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('shift_plans', function (Blueprint $table) {
            $table->id();
            $table->date('planning_date');
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('site_id');
            $table->decimal('target_bcm', 10, 2);
            $table->unsignedBigInteger('supervisor_id');
            $table->unsignedBigInteger('site_incharge_id');
            $table->unsignedInteger('equipment_count')->default(0);
            $table->enum('status', ['draft', 'planned', 'active', 'closed'])->default('draft');
            $table->unsignedBigInteger('created_by');
            $table->string('reference_no')->unique();
            $table->timestamps();

            // Unique composite index to prevent duplicate plans
            $table->unique(['planning_date', 'shift_id', 'site_id']);

            // Foreign Key constraints
            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts')
                ->onDelete('restrict');

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->onDelete('restrict');

            $table->foreign('supervisor_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('site_incharge_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shift_plans');
    }
}
