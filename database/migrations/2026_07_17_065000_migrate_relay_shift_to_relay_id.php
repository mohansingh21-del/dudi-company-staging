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
        // Get the dynamic relay IDs based on name
        $relayA = DB::table('relays')->where('name', 'Relay A')->value('id');
        $relayB = DB::table('relays')->where('name', 'Relay B')->value('id');
        $relayC = DB::table('relays')->where('name', 'Relay C')->value('id');
        $general = DB::table('relays')->where('name', 'General')->value('id');

        // Map old string enum value to new ID
        $map = [
            'relay_1' => $relayA,
            'relay_2' => $relayB,
            'relay_3' => $relayC,
            'general' => $general,
        ];

        // 1. Update employees table
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('relay_id')->nullable()->constrained('relays')->nullOnDelete();
        });

        foreach ($map as $oldVal => $newId) {
            if ($newId) {
                DB::table('employees')->where('relay_shift', $oldVal)->update(['relay_id' => $newId]);
            }
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('relay_shift');
        });

        // 2. Update relay_shift_mappings table
        Schema::table('relay_shift_mappings', function (Blueprint $table) {
            // Drop unique constraint first
            $table->dropUnique('unique_week_relay');
            $table->dropIndex('idx_relay_week_lookup');

            $table->foreignId('relay_id')->nullable()->constrained('relays')->cascadeOnDelete();
        });

        foreach ($map as $oldVal => $newId) {
            if ($newId) {
                DB::table('relay_shift_mappings')->where('relay_shift', $oldVal)->update(['relay_id' => $newId]);
            }
        }

        Schema::table('relay_shift_mappings', function (Blueprint $table) {
            $table->dropColumn('relay_shift');

            $table->unique(['week_start_date', 'relay_id'], 'unique_week_relay');
            $table->index(['relay_id', 'week_start_date', 'week_end_date'], 'idx_relay_week_lookup');
        });

        // 3. Update shift_workforce_deployments table
        Schema::table('shift_workforce_deployments', function (Blueprint $table) {
            $table->foreignId('relay_id')->nullable()->constrained('relays')->nullOnDelete();
            $table->foreignId('home_relay_id')->nullable()->constrained('relays')->nullOnDelete();
        });

        foreach ($map as $oldVal => $newId) {
            if ($newId) {
                DB::table('shift_workforce_deployments')->where('relay_shift', $oldVal)->update(['relay_id' => $newId]);
                DB::table('shift_workforce_deployments')->where('home_relay_shift', $oldVal)->update(['home_relay_id' => $newId]);
            }
        }

        Schema::table('shift_workforce_deployments', function (Blueprint $table) {
            $table->dropColumn(['relay_shift', 'home_relay_shift']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Simple rollback not needed as we are in active dev phase, but we declare schema
    }
};
