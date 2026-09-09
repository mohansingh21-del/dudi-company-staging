<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frozen Form E registers.
     *
     * Leave balances are otherwise computed live, so a past year's figures can
     * shift whenever a quota is edited, a leave is back-dated or an attendance
     * correction lands. Generating a report freezes the numbers as they stood,
     * which is what an inspector was actually shown.
     *
     * There is at most one report per year. Generating a year that already has
     * one replaces it in place — the API asks for confirmation first — and
     * bumps `version`, so the row records how many times it has been redone.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('leave_register_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');

            // How many times this year has been generated, not a separate row
            // per generation — the unique key below allows only one per year.
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();

            $table->unsignedInteger('employee_count')->default(0);

            // What the leave master looked like at generation time. Quotas are
            // not versioned, so without this there is no way to explain why an
            // old report shows the numbers it does.
            $table->json('leave_type_snapshot')->nullable();

            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique('year');
        });

        Schema::create('leave_register_report_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('leave_register_reports')->cascadeOnDelete();

            // Kept for traceability, but the name and code are copied in below:
            // a frozen register must still read correctly if the employee record
            // is later renamed or deleted.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->unsignedInteger('serial_no');            // col 1
            $table->string('employee_code')->nullable();
            $table->string('employee_name');                 // col 2
            $table->decimal('days_worked', 6, 1)->default(0); // col 3

            // Columns 4-8: Compensatory Rest
            $table->decimal('comp_rest_opening', 6, 1)->default(0);
            $table->decimal('comp_rest_added', 6, 1)->default(0);
            $table->decimal('comp_rest_not_allowed', 6, 1)->default(0);
            $table->decimal('comp_rest_availed', 6, 1)->default(0);
            $table->decimal('comp_rest_closing', 6, 1)->default(0);

            // Columns 9-12: Earned Leave
            $table->decimal('earned_opening', 6, 1)->default(0);
            $table->decimal('earned_added', 6, 1)->default(0);
            $table->decimal('earned_availed', 6, 1)->default(0);
            $table->decimal('earned_closing', 6, 1)->default(0);

            // Columns 13-16: Medical Leave
            $table->decimal('medical_opening', 6, 1)->default(0);
            $table->decimal('medical_added', 6, 1)->default(0);
            $table->decimal('medical_availed', 6, 1)->default(0);
            $table->decimal('medical_closing', 6, 1)->default(0);

            // Columns 17-20: Other Leave
            $table->decimal('other_opening', 6, 1)->default(0);
            $table->decimal('other_added', 6, 1)->default(0);
            $table->decimal('other_availed', 6, 1)->default(0);
            $table->decimal('other_closing', 6, 1)->default(0);

            $table->text('remarks')->nullable();             // col 25
            $table->timestamps();

            $table->index(['report_id', 'serial_no']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('leave_register_report_rows');
        Schema::dropIfExists('leave_register_reports');
    }
};
