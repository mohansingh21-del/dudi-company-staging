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
        // 1. fuel_entries
        Schema::table('fuel_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('mine_site_id')->nullable()->after('shift_id');
            $table->unsignedBigInteger('block_id')->nullable()->after('mine_site_id');
            $table->date('date')->nullable()->after('block_id');

            $table->foreign('mine_site_id')->references('id')->on('sites')->onDelete('restrict');
            $table->index(['mine_site_id', 'block_id', 'shift_id', 'date'], 'fuel_entries_dash_idx');
        });

        // 2. breakdown_tickets
        Schema::table('breakdown_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('mine_site_id')->nullable()->after('shift_id');
            $table->unsignedBigInteger('block_id')->nullable()->after('mine_site_id');
            $table->date('date')->nullable()->after('block_id');

            $table->foreign('mine_site_id')->references('id')->on('sites')->onDelete('restrict');
            $table->index(['mine_site_id', 'block_id', 'shift_id', 'date'], 'breakdown_tickets_dash_idx');
        });

        // 3. delays
        Schema::table('delays', function (Blueprint $table) {
            $table->unsignedBigInteger('mine_site_id')->nullable()->after('shift_id');
            $table->unsignedBigInteger('block_id')->nullable()->after('mine_site_id');
            $table->date('date')->nullable()->after('block_id');

            $table->foreign('mine_site_id')->references('id')->on('sites')->onDelete('restrict');
            $table->index(['mine_site_id', 'block_id', 'shift_id', 'date'], 'delays_dash_idx');
        });

        // 4. dispatch_trips
        Schema::table('dispatch_trips', function (Blueprint $table) {
            $table->unsignedBigInteger('mine_site_id')->nullable()->after('site_id');
            $table->unsignedBigInteger('block_id')->nullable()->after('mine_site_id');
            $table->date('date')->nullable()->after('block_id');

            $table->foreign('mine_site_id')->references('id')->on('sites')->onDelete('restrict');
            $table->index(['mine_site_id', 'block_id', 'shift_id', 'date'], 'dispatch_trips_dash_idx');
        });

        // Populate existing data
        try {
            DB::statement("
                UPDATE fuel_entries fe
                INNER JOIN shift_plans sp ON fe.shift_plan_id = sp.id
                SET fe.mine_site_id = sp.site_id,
                    fe.date = DATE(fe.fuel_log_date)
            ");
        } catch (\Exception $e) {}

        try {
            DB::statement("
                UPDATE breakdown_tickets bt
                INNER JOIN shift_plans sp ON bt.shift_id = sp.shift_id AND DATE(bt.breakdown_date_time) = sp.planning_date
                SET bt.mine_site_id = sp.site_id,
                    bt.date = DATE(bt.breakdown_date_time)
            ");
        } catch (\Exception $e) {}

        try {
            DB::statement("
                UPDATE delays d
                INNER JOIN shift_plans sp ON d.shift_plan_id = sp.id
                SET d.mine_site_id = sp.site_id,
                    d.date = d.shift_date
            ");
        } catch (\Exception $e) {}

        try {
            DB::statement("
                UPDATE dispatch_trips dt
                SET dt.mine_site_id = dt.site_id,
                    dt.date = DATE(dt.trip_date_time)
            ");
        } catch (\Exception $e) {}
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('fuel_entries', function (Blueprint $table) {
            $table->dropForeign(['mine_site_id']);
            $table->dropIndex('fuel_entries_dash_idx');
            $table->dropColumn(['mine_site_id', 'block_id', 'date']);
        });

        Schema::table('breakdown_tickets', function (Blueprint $table) {
            $table->dropForeign(['mine_site_id']);
            $table->dropIndex('breakdown_tickets_dash_idx');
            $table->dropColumn(['mine_site_id', 'block_id', 'date']);
        });

        Schema::table('delays', function (Blueprint $table) {
            $table->dropForeign(['mine_site_id']);
            $table->dropIndex('delays_dash_idx');
            $table->dropColumn(['mine_site_id', 'block_id', 'date']);
        });

        Schema::table('dispatch_trips', function (Blueprint $table) {
            $table->dropForeign(['mine_site_id']);
            $table->dropIndex('dispatch_trips_dash_idx');
            $table->dropColumn(['mine_site_id', 'block_id', 'date']);
        });
    }
};
