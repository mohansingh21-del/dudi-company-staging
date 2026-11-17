<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RenameSiteInchargeIdToShiftInchargeIdOnShiftPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * A shift plan is now owned by a Shift Incharge instead of a Site
     * Incharge. Existing values carry over: the column still holds a users.id.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shift_plans', function (Blueprint $table) {
            $table->dropForeign('shift_plans_site_incharge_id_foreign');
        });

        Schema::table('shift_plans', function (Blueprint $table) {
            $table->renameColumn('site_incharge_id', 'shift_incharge_id');
        });

        Schema::table('shift_plans', function (Blueprint $table) {
            $table->foreign('shift_incharge_id')
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
        Schema::table('shift_plans', function (Blueprint $table) {
            $table->dropForeign('shift_plans_shift_incharge_id_foreign');
        });

        Schema::table('shift_plans', function (Blueprint $table) {
            $table->renameColumn('shift_incharge_id', 'site_incharge_id');
        });

        Schema::table('shift_plans', function (Blueprint $table) {
            $table->foreign('site_incharge_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
        });
    }
}
