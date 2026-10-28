<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChangeSupervisorIdToCsvOnShiftPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * A shift plan can now carry up to 5 supervisors, stored as comma-separated
     * user IDs in supervisor_id (e.g. "3,7,12"); the first one is the primary.
     * The foreign key can't apply to a list, so it is dropped.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shift_plans', function (Blueprint $table) {
            $table->dropForeign('shift_plans_supervisor_id_foreign');
        });

        DB::statement('ALTER TABLE shift_plans MODIFY supervisor_id VARCHAR(255) NOT NULL');
    }

    /**
     * Reverse the migrations.
     *
     * Keeps only the primary (first) supervisor of each plan.
     *
     * @return void
     */
    public function down()
    {
        DB::statement("UPDATE shift_plans SET supervisor_id = SUBSTRING_INDEX(supervisor_id, ',', 1)");
        DB::statement('ALTER TABLE shift_plans MODIFY supervisor_id BIGINT UNSIGNED NOT NULL');

        Schema::table('shift_plans', function (Blueprint $table) {
            $table->foreign('supervisor_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
        });
    }
}
