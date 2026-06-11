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
        Schema::create('vehicle_permits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->enum('permit_type', ['national', 'state']);
            $table->string('state')->nullable(); // Required only if permit_type == 'state'
            $table->string('permit_number');
            $table->string('permit_holder')->nullable();
            $table->string('father_name')->nullable();
            $table->text('address')->nullable();
            $table->date('registration_date')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('compliance_document')->nullable();
            $table->decimal('document_charges', 10, 2)->nullable();
            $table->date('NP_dated')->nullable(); // Previously issue_date
            $table->date('valid_from')->nullable();
            $table->date('valid_upto')->nullable();
            $table->string('document_path')->nullable();
            $table->enum('status', ['active', 'expired'])->default('active');
            $table->timestamps();
            
            $table->index(['valid_upto', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vehicle_permits');
    }
};
