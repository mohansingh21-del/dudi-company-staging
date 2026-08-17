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
        // 1. Add columns as nullable first to prevent failure on tables with existing rows
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('vehicle_number')->nullable()->after('id');
            $table->string('owner_name')->nullable()->after('vehicle_number');
            $table->tinyInteger('is_active')->default(1)->after('owner_name');
        });

        // 2. Populate existing rows with unique placeholder values
        $vehicles = DB::table('vehicles')->get();
        foreach ($vehicles as $vehicle) {
            DB::table('vehicles')
                ->where('id', $vehicle->id)
                ->update([
                    'vehicle_number' => 'TEMP-' . $vehicle->id,
                ]);
        }

        // 3. Make the vehicle_number NOT NULL and UNIQUE using raw SQL (avoiding doctrine/dbal requirement)
        DB::statement('ALTER TABLE vehicles MODIFY vehicle_number VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE vehicles ADD UNIQUE INDEX vehicles_vehicle_number_unique (vehicle_number)');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropUnique(['vehicle_number']);
            $table->dropColumn(['vehicle_number', 'owner_name', 'is_active']);
        });
    }
};
