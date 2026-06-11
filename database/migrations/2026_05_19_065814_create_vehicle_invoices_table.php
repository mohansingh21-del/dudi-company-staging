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
        Schema::create('vehicle_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('dealer_invoice_number')->unique();
            $table->date('invoice_date');
            $table->date('GRN_date')->nullable();
            $table->decimal('base_price', 15, 2);
            $table->decimal('invoice_price', 15, 2);
            $table->string('dealer_name');
            $table->string('document_path')->nullable(); // Path to PDF/Image
            $table->string('financed_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vehicle_invoices');
    }
};
