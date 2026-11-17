<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProductionInchargeIdToShiftPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * A shift plan also names a Production Incharge (a users.id, like
     * shift_incharge_id). Nullable because plans created before this have none.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shift_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('production_incharge_id')->nullable()->after('shift_incharge_id');

            $table->foreign('production_incharge_id')
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
            $table->dropForeign('shift_plans_production_incharge_id_foreign');
            $table->dropColumn('production_incharge_id');
        });
    }
}
