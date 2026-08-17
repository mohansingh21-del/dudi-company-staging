<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceSparePartsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('service_spare_parts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_record_id');

            $table->enum('source', ['inventory', 'vendor'])->default('inventory');
            $table->unsignedBigInteger('inventory_product_id')->nullable();

            $table->string('part_name');
            $table->string('vendor_name')->nullable();

            $table->decimal('quantity', 10, 2)->default(1.00);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('amount', 10, 2)->default(0.00);

            $table->timestamps();

            $table->foreign('service_record_id')
                ->references('id')
                ->on('service_records')
                ->onDelete('cascade');

            if (Schema::hasTable('inventory_products')) {
                $table->foreign('inventory_product_id')->references('id')->on('inventory_products')->onDelete('set null');
            } elseif (Schema::hasTable('products')) {
                $table->foreign('inventory_product_id')->references('id')->on('products')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('service_spare_parts');
    }
}
