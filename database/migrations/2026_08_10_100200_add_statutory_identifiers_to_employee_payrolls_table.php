<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statutory scheme memberships live with the salary details, not on the
     * employee record — bank details are already here.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->string('uan', 12)->nullable()->after('pf_number');           // col 15
            $table->string('esic_ip_number', 20)->nullable()->after('uan');      // col 17
            $table->string('lwf_number')->nullable()->after('esic_ip_number');   // col 18

            $table->index('uan');
        });

        DB::statement("ALTER TABLE employee_payrolls MODIFY salary_type
            ENUM('monthly','daily_wage','piece_rate') NOT NULL DEFAULT 'monthly'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('employee_payrolls', function (Blueprint $table) {
            $table->dropIndex(['uan']);
            $table->dropColumn(['uan', 'esic_ip_number', 'lwf_number']);
        });

        DB::statement("ALTER TABLE employee_payrolls MODIFY salary_type
            ENUM('monthly','daily_wage') NOT NULL DEFAULT 'monthly'");
    }
};
