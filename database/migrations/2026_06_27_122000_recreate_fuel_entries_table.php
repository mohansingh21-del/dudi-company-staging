<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RecreateFuelEntriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('fuel_entries');

        Schema::create('fuel_entries', function (Blueprint $table) {
            $table->id();
            $table->string('fuel_ref_no', 30)->unique();
            $table->unsignedBigInteger('shift_plan_id');
            $table->unsignedBigInteger('equipment_allocation_id');
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->enum('fuel_source', ['fuel_tanker', 'fuel_station', 'mobile_refueling_unit'])->nullable();
            $table->decimal('opening_fuel', 10, 2);
            $table->decimal('fuel_issued', 10, 2);
            $table->decimal('closing_fuel', 10, 2)->nullable();
            $table->decimal('fuel_consumption', 10, 2);
            $table->decimal('work_done_bcm', 10, 2)->nullable();
            $table->decimal('fuel_per_bcm', 10, 4)->nullable();
            $table->decimal('hours_meter_reading', 10, 2)->nullable();
            $table->decimal('kilometer_reading', 10, 2)->nullable();
            $table->decimal('fuel_per_hour', 10, 4)->nullable();
            $table->decimal('fuel_per_km', 10, 4)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->enum('status', ['active', 'voided'])->default('active');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('shift_plan_id')
                ->references('id')
                ->on('shift_plans')
                ->onDelete('restrict');

            $table->foreign('equipment_allocation_id')
                ->references('id')
                ->on('shift_equipment_allocations')
                ->onDelete('restrict');

            $table->foreign('operator_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index('shift_plan_id');
            $table->index('equipment_allocation_id');
            $table->index(['equipment_allocation_id', 'hours_meter_reading']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('fuel_entries');
    }
}
