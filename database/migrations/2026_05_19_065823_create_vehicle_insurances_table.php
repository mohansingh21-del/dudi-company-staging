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
        Schema::create('vehicle_insurances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('provider');
            $table->string('policy_number')->unique();
            $table->enum('insurance_type', ['comprehensive', 'third_party']);
            $table->date('issue_date');
            $table->date('expiry_date');
            $table->decimal('od_premium_amt', 10, 2)->nullable();
            $table->decimal('od_deductions', 10, 2)->nullable();
            $table->decimal('od_additions', 10, 2)->nullable();
            $table->decimal('total_od_premium', 10, 2)->nullable();
            $table->decimal('tp_premium', 10, 2)->nullable();
            $table->decimal('tp_deductions', 10, 2)->nullable();
            $table->decimal('tp_additions', 10, 2)->nullable();
            $table->decimal('total_tp_premium', 10, 2)->nullable();
            $table->decimal('total_premium', 10, 2)->nullable();
            $table->decimal('gst_amount', 10, 2)->nullable();
            $table->decimal('cess_amount', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->string('nominee_name')->nullable();
            $table->string('relation')->nullable();
            $table->integer('age')->nullable();
            $table->string('document_path')->nullable();
            $table->enum('status', ['active', 'expired'])->default('active');
            $table->timestamps();
            
            $table->index('expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vehicle_insurances');
    }
};
