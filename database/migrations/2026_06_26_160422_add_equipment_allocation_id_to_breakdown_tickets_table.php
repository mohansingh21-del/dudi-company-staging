<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEquipmentAllocationIdToBreakdownTicketsTable extends Migration
{
    public function up()
    {
        Schema::table('breakdown_tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('equipment_allocation_id')->nullable()->after('equipment_name_id');

            $table->foreign('equipment_allocation_id')
                ->references('id')
                ->on('shift_equipment_allocations')
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
        Schema::table('breakdown_tickets', function (Blueprint $table) {
            $table->dropForeign(['equipment_allocation_id']);
            $table->dropColumn('equipment_allocation_id');
        });
    }
}
