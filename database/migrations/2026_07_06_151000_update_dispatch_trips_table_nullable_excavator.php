<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('dispatch_trips', function (Blueprint $table) {
            $table->unsignedBigInteger('excavator_equipment_id')->nullable()->change();
            if (!Schema::hasColumn('dispatch_trips', 'total_cycles')) {
                $table->integer('total_cycles')->default(1)->after('distance_meters');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('dispatch_trips', function (Blueprint $table) {
            $table->unsignedBigInteger('excavator_equipment_id')->nullable(false)->change();
            if (Schema::hasColumn('dispatch_trips', 'total_cycles')) {
                $table->dropColumn('total_cycles');
            }
        });
    }
};
