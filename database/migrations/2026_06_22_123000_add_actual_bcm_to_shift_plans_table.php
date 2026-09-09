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
        Schema::table('shift_plans', function (Blueprint $table) {
            $table->decimal('actual_bcm', 10, 2)->default(0.00)->after('target_bcm');
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
            $table->dropColumn('actual_bcm');
        });
    }
};
