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
        // Expand status enum to include 'completed'
        DB::statement("ALTER TABLE shift_plans MODIFY COLUMN status ENUM('draft', 'published', 'in_progress', 'completed', 'planned', 'active', 'closed') NOT NULL DEFAULT 'draft'");

        Schema::table('shift_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('closed_by')->nullable()->after('status');
            $table->date('closure_date')->nullable()->after('closed_by');
            $table->time('closure_time')->nullable()->after('closure_date');
            $table->text('supervisor_remarks')->nullable()->after('closure_time');
            $table->text('handover_notes')->nullable()->after('supervisor_remarks');
            $table->boolean('closure_confirmed')->default(false)->after('handover_notes');
            $table->text('breakdown_justification')->nullable()->after('closure_confirmed');
            $table->json('shift_summary_snapshot')->nullable()->after('breakdown_justification');

            $table->foreign('closed_by')
                ->references('id')
                ->on('users')
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
        Schema::table('shift_plans', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropColumn([
                'closed_by',
                'closure_date',
                'closure_time',
                'supervisor_remarks',
                'handover_notes',
                'closure_confirmed',
                'breakdown_justification',
                'shift_summary_snapshot',
            ]);
        });

        DB::statement("ALTER TABLE shift_plans MODIFY COLUMN status ENUM('draft', 'published', 'in_progress', 'planned', 'active', 'closed') NOT NULL DEFAULT 'draft'");
    }
};
