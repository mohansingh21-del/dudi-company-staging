<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            $table->text('description')->nullable();

            $table->string('image')->nullable();

            $table->unsignedMediumInteger('state_id');

            $table->unsignedMediumInteger('country_id');

            $table->decimal('latitude', 10, 8)->nullable();

            $table->integer('is_active')->default(1);

            $table->decimal('longitude', 11, 8)->nullable();

            $table->timestamp('created_at')
                ->default('2013-12-31 20:01:01');

            $table->timestamp('updated_at')
                ->useCurrent()
                ->useCurrentOnUpdate();

            $table->softDeletes();

            // Optional Foreign Keys
            // $table->foreign('state_id')
            //       ->references('id')
            //       ->on('states')
            //       ->onDelete('cascade');

            // $table->foreign('country_id')
            //       ->references('id')
            //       ->on('countries')
            //       ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('cities');
    }
};
