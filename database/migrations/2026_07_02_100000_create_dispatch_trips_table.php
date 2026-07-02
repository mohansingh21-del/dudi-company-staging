<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDispatchTripsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('dispatch_trips', function (Blueprint $table) {
            $table->id();
            $table->string('trip_reference_no', 30)->unique();
            $table->unsignedBigInteger('shift_plan_id');
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('dumper_equipment_id'); // references equipment_names.id
            $table->unsignedBigInteger('driver_id');
            $table->unsignedBigInteger('excavator_equipment_id'); // references equipment_names.id
            $table->unsignedBigInteger('loading_point_id');
            $table->unsignedBigInteger('dumping_point_id');
            $table->dateTime('trip_date_time');
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->decimal('cycle_time_minutes', 8, 2);
            $table->decimal('quantity_bcm', 10, 2);
            $table->decimal('distance_meters', 10, 2)->nullable();
            $table->string('status', 20)->default('logged');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('shift_plan_id')
                ->references('id')
                ->on('shift_plans')
                ->onDelete('restrict');

            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts')
                ->onDelete('restrict');

            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->onDelete('restrict');

            // Note: points to equipment_names because equipment_names represents the actual dumper machine instance
            $table->foreign('dumper_equipment_id')
                ->references('id')
                ->on('equipment_names')
                ->onDelete('restrict');

            $table->foreign('driver_id')
                ->references('id')
                ->on('employees')
                ->onDelete('restrict');

            // Note: points to equipment_names because equipment_names represents the actual excavator machine instance
            $table->foreign('excavator_equipment_id')
                ->references('id')
                ->on('equipment_names')
                ->onDelete('restrict');

            $table->foreign('loading_point_id')
                ->references('id')
                ->on('site_points')
                ->onDelete('restrict');

            $table->foreign('dumping_point_id')
                ->references('id')
                ->on('site_points')
                ->onDelete('restrict');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Single indexes
            $table->index('dumper_equipment_id');
            $table->index('excavator_equipment_id');
            $table->index('shift_plan_id');
            $table->index('shift_id');
            $table->index('site_id');
            $table->index('loading_point_id');
            $table->index('dumping_point_id');
            $table->index('trip_date_time');
            $table->index('start_time');

            // Composite index for KPI performance
            $table->index(['dumper_equipment_id', 'start_time']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('dispatch_trips');
    }
}
