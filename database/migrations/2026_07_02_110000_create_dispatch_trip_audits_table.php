<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDispatchTripAuditsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('dispatch_trip_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dispatch_trip_id');
            $table->json('changed_fields');
            $table->unsignedBigInteger('changed_by');
            $table->timestamp('changed_at');
            $table->timestamps();

            // Foreign keys
            $table->foreign('dispatch_trip_id')
                ->references('id')
                ->on('dispatch_trips')
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
        Schema::dropIfExists('dispatch_trip_audits');
    }
}
