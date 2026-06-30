<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDelaysTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('delays', function (Blueprint $table) {
            $table->id();
            $table->string('delay_ref_no', 30)->unique();
            $table->unsignedBigInteger('shift_plan_id');
            $table->unsignedBigInteger('shift_id');
            $table->date('shift_date');
            $table->string('shift_name', 50);
            $table->unsignedBigInteger('delay_category_id');
            $table->string('delay_subcategory')->nullable();
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);
            $table->unsignedBigInteger('linked_breakdown_id')->nullable();
            $table->unsignedBigInteger('equipment_id')->nullable();
            $table->unsignedBigInteger('equipment_name_id')->nullable();
            $table->decimal('average_production_rate_per_hour', 10, 2)->nullable();
            $table->decimal('estimated_production_loss_bcm', 12, 2)->nullable();
            $table->text('description');
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Foreign Keys
            $table->foreign('delay_category_id')
                ->references('id')
                ->on('delay_categories')
                ->onDelete('restrict');

            $table->foreign('shift_plan_id')
                ->references('id')
                ->on('shift_plans')
                ->onDelete('restrict');

            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts')
                ->onDelete('restrict');

            $table->foreign('linked_breakdown_id')
                ->references('id')
                ->on('breakdown_tickets')
                ->onDelete('set null');

            $table->foreign('equipment_id')
                ->references('id')
                ->on('equipments')
                ->onDelete('restrict');

            $table->foreign('equipment_name_id')
                ->references('id')
                ->on('equipment_names')
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
            $table->index(['shift_plan_id', 'delay_category_id']);
            $table->index(['start_time', 'end_time']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('delays');
    }
}
