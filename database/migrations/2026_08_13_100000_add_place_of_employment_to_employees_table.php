<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the worker is engaged, recorded once at registration. The
     * attendance register keeps its own per-day `place_of_work` because a
     * worker can be moved between places within a month; this column is the
     * standing assignment the employee was enrolled against, and shares the
     * same three values so the two can be compared.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->enum('place_of_employment', [
                'underground',
                'opencast',
                'surface',
            ])->nullable()->after('site_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('place_of_employment');
        });
    }
};
