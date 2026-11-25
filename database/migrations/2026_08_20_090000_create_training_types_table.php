<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * training_types predates the migrations that reference it — it was created out
 * of band, so a fresh database had no way to build it and create_trainings_table
 * failed on its foreign key. Dated ahead of that migration so the order holds.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('training_types')) {
            return;
        }

        Schema::create('training_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('training_types');
    }
};
