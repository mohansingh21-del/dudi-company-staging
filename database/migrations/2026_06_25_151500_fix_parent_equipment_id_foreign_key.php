<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixParentEquipmentIdForeignKey extends Migration
{
    /**
     * Run the migrations.
     *
     * The parent_equipment_id column was incorrectly referencing the
     * `equipments` table (categories) instead of `equipment_names`
     * (machine instances). This caused FK constraint violations when
     * nesting a dumper under a specific excavator machine.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shift_equipment_allocations', function (Blueprint $table) {
            // Drop the incorrect foreign key
            $table->dropForeign('shift_equipment_allocations_parent_equipment_id_foreign');

            // Re-add the foreign key pointing to equipment_names
            $table->foreign('parent_equipment_id')
                ->references('id')
                ->on('equipment_names')
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
        Schema::table('shift_equipment_allocations', function (Blueprint $table) {
            $table->dropForeign('shift_equipment_allocations_parent_equipment_id_foreign');

            $table->foreign('parent_equipment_id')
                ->references('id')
                ->on('equipments')
                ->onDelete('restrict');
        });
    }
}
