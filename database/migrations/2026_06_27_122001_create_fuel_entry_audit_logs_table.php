<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFuelEntryAuditLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fuel_entry_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fuel_entry_id');
            $table->unsignedBigInteger('changed_by');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->nullable();

            // Foreign keys
            $table->foreign('fuel_entry_id')
                ->references('id')
                ->on('fuel_entries')
                ->onDelete('cascade');

            $table->foreign('changed_by')
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
        Schema::dropIfExists('fuel_entry_audit_logs');
    }
}
