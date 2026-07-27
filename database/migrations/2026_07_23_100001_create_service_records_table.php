<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('service_records', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique();

            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('site_id')->nullable();

            $table->boolean('is_breakdown_service')->default(false);
            $table->unsignedBigInteger('breakdown_id')->nullable();

            $table->enum('service_type', ['general', 'repair'])->default('general');
            $table->date('service_date');
            $table->decimal('hours_odometer_reading', 12, 2)->nullable();
            $table->decimal('km_run', 12, 2)->nullable();
            $table->unsignedSmallInteger('time_gap_months')->nullable();

            $table->decimal('base_service_amount', 12, 2)->default(0.00);
            $table->decimal('checklist_amount_total', 12, 2)->default(0.00);
            $table->decimal('spare_parts_amount_total', 12, 2)->default(0.00);
            $table->decimal('total_amount', 12, 2)->default(0.00);

            $table->boolean('spare_parts_changed')->default(false);
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->string('performed_by')->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            if (Schema::hasTable('machines')) {
                $table->foreign('machine_id')->references('id')->on('machines')->onDelete('restrict');
            } elseif (Schema::hasTable('equipment_names')) {
                $table->foreign('machine_id')->references('id')->on('equipment_names')->onDelete('restrict');
            }

            if (Schema::hasTable('sites')) {
                $table->foreign('site_id')->references('id')->on('sites')->onDelete('set null');
            }

            if (Schema::hasTable('breakdowns')) {
                $table->foreign('breakdown_id')->references('id')->on('breakdowns')->onDelete('set null');
            } elseif (Schema::hasTable('breakdown_tickets')) {
                $table->foreign('breakdown_id')->references('id')->on('breakdown_tickets')->onDelete('set null');
            }

            if (Schema::hasTable('users')) {
                $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            }

            // Indexes
            $table->index(['machine_id', 'service_date']);
            $table->index('status');
            $table->index(['is_breakdown_service', 'breakdown_id']);
            $table->index('site_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('service_records');
    }
}
