<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShiftEquipmentAllocationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('shift_equipment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_plan_id');
            $table->unsignedBigInteger('equipment_name_id'); // actual machine instance
            $table->unsignedBigInteger('parent_equipment_id')->nullable(); // category machine is nested under
            $table->unsignedBigInteger('allocated_by');
            $table->timestamp('allocation_time');
            $table->timestamps();

            // Indexes for fast querying
            $table->index('equipment_name_id');
            $table->index('shift_plan_id');

            // Foreign Key constraints
            $table->foreign('shift_plan_id')
                ->references('id')
                ->on('shift_plans')
                ->onDelete('cascade');

            $table->foreign('equipment_name_id')
                ->references('id')
                ->on('equipment_names')
                ->onDelete('restrict');

            $table->foreign('parent_equipment_id')
                ->references('id')
                ->on('equipments')
                ->onDelete('restrict');

            $table->foreign('allocated_by')
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
        Schema::dropIfExists('shift_equipment_allocations');
    }
}
