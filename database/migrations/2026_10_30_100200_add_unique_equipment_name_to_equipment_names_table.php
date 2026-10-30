<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddUniqueEquipmentNameToEquipmentNamesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * equipment_name now carries the machine's code and/or name, the label an
     * operator identifies it by. Validation already refuses duplicates; the
     * index closes the gap two simultaneous requests could slip through.
     *
     * @return void
     */
    public function up()
    {
        $duplicates = DB::table('equipment_names')
            ->select('equipment_name')
            ->groupBy('equipment_name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('equipment_name');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Rename the duplicate equipment names before migrating: '
                . $duplicates->implode(', ')
            );
        }

        Schema::table('equipment_names', function (Blueprint $table) {
            $table->unique('equipment_name', 'equipment_names_equipment_name_unique');
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
            $table->dropUnique('equipment_names_equipment_name_unique');
        });
    }
}
