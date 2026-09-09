<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PAN and Aadhaar sit with the statutory identifiers on the salary record.
     * Verified before moving: no employee row had either populated.
     *
     * aadhaar_number is encrypted, so it cannot be indexed or searched —
     * aadhaar_last4 serves display, aadhaar_hash catches duplicate enrolment.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->string('pan', 10)->nullable()->after('lwf_number');
            $table->text('aadhaar_number')->nullable()->after('pan');
            $table->string('aadhaar_last4', 4)->nullable()->after('aadhaar_number');
            $table->string('aadhaar_hash', 64)->nullable()->after('aadhaar_last4');

            $table->unique('pan');
            $table->unique('aadhaar_hash');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['pan']);
            $table->dropUnique(['aadhaar_hash']);
            $table->dropColumn(['pan', 'aadhaar_number', 'aadhaar_last4', 'aadhaar_hash']);
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
            $table->string('pan', 10)->nullable()->after('mobile');
            $table->text('aadhaar_number')->nullable()->after('pan');
            $table->string('aadhaar_last4', 4)->nullable()->after('aadhaar_number');
            $table->string('aadhaar_hash', 64)->nullable()->after('aadhaar_last4');

            $table->unique('pan');
            $table->unique('aadhaar_hash');
        });

        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropUnique(['pan']);
            $table->dropUnique(['aadhaar_hash']);
            $table->dropColumn(['pan', 'aadhaar_number', 'aadhaar_last4', 'aadhaar_hash']);
        });
    }
};
