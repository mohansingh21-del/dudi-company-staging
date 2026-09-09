<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staging for an edited Form B spreadsheet.
     *
     * The user downloads the calculated register, corrects it by hand and sends
     * it back. What comes back is held here and nowhere else: it never writes to
     * employee_payrolls, payrolls or any other table those amounts came from.
     * The sheet is the user's working copy, not a correction to the masters.
     *
     * A month has at most one staging batch. Re-uploading replaces it, so a
     * corrected sheet supersedes the one before it rather than accumulating.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('wage_register_uploads', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename')->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);

            // pending — rows failed validation, the user has to fix and resend
            // ready   — every row is clean and can be submitted
            //
            // There is no "submitted" state: a submitted batch is deleted, and
            // the register it produced is the record from then on. Keeping a
            // copy here would only invite the two to drift apart.
            $table->enum('status', ['pending', 'ready'])->default('pending');

            $table->timestamps();

            $table->unique(['month', 'year']);
        });

        Schema::create('wage_register_upload_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained('wage_register_uploads')->cascadeOnDelete();

            // The spreadsheet row this came from, so an error can be pointed at
            // the exact line the user is looking at.
            $table->unsignedInteger('excel_row');

            // Null when the code in column 1 matched no employee — the row is
            // still kept so the user can see why it was rejected.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('employee_code')->nullable();
            $table->string('employee_name')->nullable();

            // Parsed values. Nullable throughout: a blank cell stays blank, and a
            // cell that failed validation is left null with the reason recorded
            // in `errors` rather than being coerced to 0.
            $table->decimal('rate_of_wage', 12, 2)->nullable();          // col 3
            $table->decimal('days_worked', 8, 2)->nullable();            // col 4
            $table->decimal('overtime_hours', 8, 2)->nullable();         // col 5
            $table->decimal('basic', 12, 2)->nullable();                 // col 6
            $table->decimal('special_basic', 12, 2)->nullable();         // col 7
            $table->decimal('dearness_allowance', 12, 2)->nullable();    // col 8
            $table->decimal('overtime_payment', 12, 2)->nullable();      // col 9
            $table->decimal('hra', 12, 2)->nullable();                   // col 10
            $table->decimal('other_earnings', 12, 2)->nullable();        // col 11
            $table->decimal('total_earnings', 12, 2)->nullable();        // col 12
            $table->decimal('pf_deduction', 12, 2)->nullable();          // col 13
            $table->decimal('esic_deduction', 12, 2)->nullable();        // col 14
            $table->decimal('society_deduction', 12, 2)->nullable();     // col 15
            $table->decimal('income_tax', 12, 2)->nullable();            // col 16
            $table->decimal('insurance', 12, 2)->nullable();             // col 17

            // The sheet prints column 18 as one figure, so it comes back as one.
            // The system's own other/mess/penalty/absence split cannot be
            // recovered from it and is deliberately not guessed at.
            $table->decimal('other_deductions', 12, 2)->nullable();      // col 18

            $table->decimal('recoveries', 12, 2)->nullable();            // col 19
            $table->decimal('total_deductions', 12, 2)->nullable();      // col 20
            $table->decimal('net_payment', 12, 2)->nullable();           // col 21
            $table->decimal('employer_pf_share', 12, 2)->nullable();     // col 22

            $table->string('payment_reference')->nullable();             // col 23
            $table->date('payment_date')->nullable();                    // col 24
            $table->text('remarks')->nullable();                         // col 25

            // Exactly what was in the sheet, before parsing. Keeps the offending
            // text available so the UI can show the user what they actually typed.
            $table->json('raw_data')->nullable();

            // Keyed by field name, so the frontend can mark the failing cell.
            $table->json('errors')->nullable();
            $table->boolean('is_valid')->default(false);

            $table->timestamps();

            $table->index(['upload_id', 'excel_row']);
            $table->index(['upload_id', 'is_valid']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wage_register_upload_rows');
        Schema::dropIfExists('wage_register_uploads');
    }
};
