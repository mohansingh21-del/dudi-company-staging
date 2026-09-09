<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceStatusHistoryTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('service_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_record_id');

            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('remarks')->nullable();

            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('service_record_id')
                ->references('id')
                ->on('service_records')
                ->onDelete('cascade');

            if (Schema::hasTable('users')) {
                $table->foreign('changed_by')->references('id')->on('users')->onDelete('set null');
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
        Schema::dropIfExists('service_status_history');
    }
}
