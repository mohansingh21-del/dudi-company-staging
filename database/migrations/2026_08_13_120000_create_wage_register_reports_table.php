<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frozen Form B wage registers, one per month.
     *
     * Same reasoning as the Form E register: pay figures are otherwise derived
     * live from attendance, the wage master and the payroll config, so a past
     * month's register would silently change whenever a rate is revised or an
     * attendance correction lands. Generating freezes what was actually paid.
     *
     * Generating a month that already has a report replaces it in place — the
     * API asks for confirmation first — and bumps `version`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('wage_register_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');

            // How many times this month has been generated. The unique key
            // below allows only one row per month, so this is not a row count.
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();

            $table->unsignedInteger('employee_count')->default(0);

            // Listing totals, stored so the month list does not have to sum
            // every row of every report to render.
            $table->decimal('total_earnings', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);

            // The Form B header — the rates in force when this was generated.
            // employee_wages revisions are dated, but a rate row can still be
            // corrected in place, so the snapshot is what was actually printed.
            $table->json('wage_rate_snapshot')->nullable();

            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['month', 'year']);
        });

        Schema::create('wage_register_report_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('wage_register_reports')->cascadeOnDelete();

            // Kept for traceability, but name and code are copied in below: a
            // frozen register must still read correctly if the employee record
            // is later renamed or removed.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->unsignedInteger('serial_no');                       // col 1
            $table->string('employee_code')->nullable();
            $table->string('employee_name');                            // col 2

            // Which wage master row priced this line, for auditability.
            $table->string('skill_category')->nullable();

            // ── Earnings, columns 3-12 ──

            // Nullable: the daily rate is not calculated for now, so it prints
            // blank. Columns 6 and 8 are still derived from the wage master.
            $table->decimal('rate_of_wage', 12, 2)->nullable();         // col 3

            $table->decimal('days_worked', 6, 2)->default(0);           // col 4
            $table->decimal('overtime_hours', 8, 2)->default(0);        // col 5
            $table->decimal('basic', 12, 2)->default(0);                // col 6

            // Nullable, not 0: there is no source for these yet, and a blank
            // cell on the printed form is honest where a 0 would not be.
            $table->decimal('special_basic', 12, 2)->nullable();        // col 7

            // Nullable: with no wage rate to give a proportion, the whole earned
            // amount goes to Basic and this column prints blank.
            $table->decimal('dearness_allowance', 12, 2)->nullable();   // col 8
            $table->decimal('overtime_payment', 12, 2)->default(0);     // col 9
            $table->decimal('hra', 12, 2)->nullable();                  // col 10
            $table->decimal('other_earnings', 12, 2)->nullable();       // col 11
            $table->decimal('total_earnings', 12, 2)->default(0);       // col 12

            // ── Deductions, columns 13-20 ──
            $table->decimal('pf_deduction', 12, 2)->default(0);         // col 13
            $table->decimal('esic_deduction', 12, 2)->nullable();       // col 14
            $table->decimal('society_deduction', 12, 2)->nullable();    // col 15
            $table->decimal('income_tax', 12, 2)->nullable();           // col 16
            $table->decimal('insurance', 12, 2)->nullable();            // col 17

            // Column 18 "Others" is printed as the sum of these four; they are
            // stored apart so a disputed figure can be traced to its source.
            $table->decimal('other_deduction', 12, 2)->default(0);
            $table->decimal('mess_deduction', 12, 2)->default(0);
            $table->decimal('penalty_deduction', 12, 2)->default(0);

            // Wages for days not worked. Columns 6-12 show the full monthly
            // entitlement, so the days an employee did not work have to come off
            // here rather than by shrinking the gross.
            $table->decimal('absence_deduction', 12, 2)->default(0);

            $table->decimal('recoveries', 12, 2)->nullable();           // col 19
            $table->decimal('total_deductions', 12, 2)->default(0);     // col 20

            $table->decimal('net_payment', 12, 2)->default(0);          // col 21
            $table->decimal('employer_pf_share', 12, 2)->nullable();    // col 22

            // Columns 23-24 are only known once wages are actually paid; they
            // are filled in by hand on the printout, so nothing writes them.
            $table->string('payment_reference')->nullable();            // col 23
            $table->date('payment_date')->nullable();                   // col 24

            $table->text('remarks')->nullable();                        // col 25
            $table->timestamps();

            $table->index(['report_id', 'serial_no']);
            $table->index(['report_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wage_register_report_rows');
        Schema::dropIfExists('wage_register_reports');
    }
};
