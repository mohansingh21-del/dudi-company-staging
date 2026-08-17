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
        Schema::create('relay_shift_mappings', function (Blueprint $table) {
            $table->id();
            $table->date('week_start_date');
            $table->date('week_end_date');
            $table->enum('relay_shift', ['relay_1', 'relay_2', 'relay_3']);
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['week_start_date', 'relay_shift'], 'unique_week_relay');
            $table->index(['relay_shift', 'week_start_date', 'week_end_date'], 'idx_relay_week_lookup');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('relay_shift_mappings');
    }
};
