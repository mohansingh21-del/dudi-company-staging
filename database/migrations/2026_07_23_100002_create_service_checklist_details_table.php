<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceChecklistDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('service_checklist_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_record_id')->unique();

            $table->boolean('oil_change')->default(false);
            $table->decimal('oil_change_amount', 10, 2)->default(0.00);

            $table->boolean('hydraulic_oil')->default(false);
            $table->decimal('hydraulic_oil_amount', 10, 2)->default(0.00);

            $table->boolean('gear_oil')->default(false);
            $table->decimal('gear_oil_amount', 10, 2)->default(0.00);

            $table->boolean('fuel_filter_change')->default(false);
            $table->decimal('fuel_filter_change_amount', 10, 2)->default(0.00);

            $table->boolean('oil_filter_change')->default(false);
            $table->decimal('oil_filter_change_amount', 10, 2)->default(0.00);

            $table->timestamps();

            $table->foreign('service_record_id')
                ->references('id')
                ->on('service_records')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('service_checklist_details');
    }
}
