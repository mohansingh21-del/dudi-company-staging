<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShiftPlanIdToIncidentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('shift_plan_id')
                ->nullable()
                ->after('shift_id')
                ->constrained('shift_plans')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropForeign(['shift_plan_id']);
            $table->dropColumn('shift_plan_id');
        });
    }
}
