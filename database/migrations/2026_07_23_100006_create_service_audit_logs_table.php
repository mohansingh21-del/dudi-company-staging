<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateServiceAuditLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('service_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_record_id');

            $table->enum('action', ['created', 'updated', 'deleted']);
            $table->json('changes')->nullable();

            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('service_record_id')
                ->references('id')
                ->on('service_records')
                ->onDelete('cascade');

            if (Schema::hasTable('users')) {
                $table->foreign('performed_by')->references('id')->on('users')->onDelete('set null');
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
        Schema::dropIfExists('service_audit_logs');
    }
}
