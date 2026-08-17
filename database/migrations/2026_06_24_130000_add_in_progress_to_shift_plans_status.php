<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("ALTER TABLE shift_plans MODIFY COLUMN status ENUM('draft', 'published', 'in_progress', 'planned', 'active', 'closed') NOT NULL DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::statement("ALTER TABLE shift_plans MODIFY COLUMN status ENUM('draft', 'published', 'planned', 'active', 'closed') NOT NULL DEFAULT 'draft'");
    }
};
