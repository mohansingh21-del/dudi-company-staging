<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Pre-populate with default logistics service types
        $defaultTypes = [
            ['name' => 'Oil Change', 'description' => 'Engine oil and oil filter replacement', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Tyre Change', 'description' => 'Tyre replacement, balancing, or rotation', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Brake Service', 'description' => 'Brake pads, discs, and fluid check/replacement', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Engine Repair', 'description' => 'Engine tuning, diagnosis, or major overhaul', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Battery Replacement', 'description' => 'Vehicle battery replacement and electrical check', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'General Servicing', 'description' => 'Regular maintenance check and servicing', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Wheel Alignment', 'description' => 'Wheel balancing and steering alignment', 'created_at' => now(), 'updated_at' => now()],
        ];

        DB::table('service_types')->insert($defaultTypes);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('service_types');
    }
};
