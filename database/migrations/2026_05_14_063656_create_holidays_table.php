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
        Schema::disableForeignKeyConstraints();
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('holiday_name');
            $table->date('holiday_date');
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('holiday_type')->nullable();
            $table->boolean('is_active')->default(1);
            $table->timestamps();
        });
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('holidays');
    }
};
