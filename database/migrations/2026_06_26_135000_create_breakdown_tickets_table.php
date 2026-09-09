<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBreakdownTicketsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * Note: The original documentation requested an index on (status, closed_at),
     * but since there is no `closed_at` column in the schema, we use `resolved_at` instead.
     * Also, `breakdown_type_id` is defined as a BIGINT UNSIGNED without a foreign key
     * constraint for now, as the breakdown_types master table will be created later.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('breakdown_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 30)->unique();
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('equipment_id');
            $table->unsignedBigInteger('equipment_name_id');
            $table->dateTime('breakdown_date_time');
            $table->unsignedBigInteger('reported_by');
            $table->unsignedBigInteger('breakdown_type_id');
            $table->unsignedBigInteger('severity_id');
            $table->text('description');
            $table->enum('status', ['open', 'in_progress', 'on_hold', 'closed'])->default('open');
            $table->dateTime('downtime_start');
            $table->dateTime('downtime_end')->nullable();
            $table->unsignedInteger('downtime_minutes')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts')
                ->onDelete('restrict');

            $table->foreign('equipment_id')
                ->references('id')
                ->on('equipments')
                ->onDelete('restrict');

            $table->foreign('equipment_name_id')
                ->references('id')
                ->on('equipment_names')
                ->onDelete('restrict');

            $table->foreign('reported_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('resolved_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index(['equipment_id', 'status']);
            $table->index(['status', 'resolved_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('breakdown_tickets');
    }
}
