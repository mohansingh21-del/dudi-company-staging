<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEquipmentCodeToEquipmentNamesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('equipment_names', function (Blueprint $table) {
            // Nullable: machines registered before this column existed have no
            // code yet. Unique still holds since MySQL allows repeated NULLs.
            $table->string('equipment_code', 50)->nullable()->after('equipment_name');

            $table->unique('equipment_code', 'equipment_names_equipment_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('equipment_names', function (Blueprint $table) {
            $table->dropUnique('equipment_names_equipment_code_unique');
            $table->dropColumn('equipment_code');
        });
    }
}
