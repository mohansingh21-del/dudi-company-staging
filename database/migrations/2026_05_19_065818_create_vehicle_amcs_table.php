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
        Schema::create('vehicle_amcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('amc_provider');
            $table->string('amc_number')->nullable();
            $table->string('amc_type')->nullable();
            $table->string('contract_number')->nullable();
            $table->date('start_date');
            $table->date('end_date'); // Expiry based
            $table->string('avg_running_year')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->decimal('amc_gst', 10, 2)->nullable();
            $table->decimal('amc_tcs', 10, 2)->nullable();
            $table->decimal('total_cost', 10, 2)->nullable();
            $table->string('payment_schedule')->nullable();
            $table->date('payment_start_date')->nullable();
            $table->string('document_path')->nullable();
            $table->enum('status', ['active', 'expired', 'renewed'])->default('active');
            $table->timestamps();
            
            $table->index(['end_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vehicle_amcs');
    }
};
