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
        if (!Schema::hasColumn('employees', 'emergency_contact')) {
            Schema::table('employees', function (Blueprint $table) {
                if (Schema::hasColumn('employees', 'phone')) {
                    $table->string('emergency_contact')->nullable()->after('phone');
                } else {
                    $table->string('emergency_contact')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('employees', 'emergency_contact')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('emergency_contact');
            });
        }
    }
};
