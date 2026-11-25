<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a leave row came from.
 *
 *   manual     — filed through Leave Management (the apply form or the bulk sheet)
 *   attendance — created automatically to back an attendance day that was marked
 *                'leave' or 'rest_day', so the register and payroll read one source
 *
 * Only 'attendance' rows are removed automatically when the attendance day moves
 * off 'leave'/'rest_day'; a 'manual' leave is never touched by attendance edits.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('status');
        });
    }

    public function down()
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
