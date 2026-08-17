<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the Employee Register (Form A) columns that were not already present.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('employees', function (Blueprint $table) {
            // ── Identity ── cols 4, 8, 9, 12, 28
            $table->string('surname')->nullable()->after('name');
            $table->string('nationality')->default('Indian')->after('dob');
            $table->string('education_level')->nullable()->after('nationality');
            $table->string('identification_mark')->nullable()->after('education_level');
            $table->foreignId('skill_category_id')->nullable()->after('designation_id')
                ->constrained('skill_categories')->nullOnDelete();

            // ── Identity documents ── cols 16, 19
            // aadhaar_number is encrypted, so it cannot be indexed or searched.
            // aadhaar_last4 serves display/lookup, aadhaar_hash catches duplicate enrolment.
            $table->string('pan', 10)->nullable()->after('mobile');
            $table->text('aadhaar_number')->nullable()->after('pan');
            $table->string('aadhaar_last4', 4)->nullable()->after('aadhaar_number');
            $table->string('aadhaar_hash', 64)->nullable()->after('aadhaar_last4');

            // ── Address ── col 24 (existing `address` is the present address)
            $table->text('permanent_address')->nullable()->after('address');

            // ── Service record & exit ── cols 25, 26, 27
            $table->string('service_book_no')->nullable()->after('joining_date');
            $table->date('date_of_exit')->nullable()->after('is_active');
            $table->string('reason_for_exit')->nullable()->after('date_of_exit');

            // ── Documents ── cols 29, 30
            $table->string('photo_path')->nullable();
            $table->string('signature_path')->nullable();

            // ── col 31 ──
            $table->text('remarks')->nullable();

            $table->unique('pan');
            $table->unique('aadhaar_hash');
            $table->index('date_of_exit');
        });

        // Split the existing single `name` column into name + surname on the last
        // whitespace token. MySQL assigns left to right, so `surname` is derived
        // before `name` is overwritten. Single-word names keep a null surname.
        DB::statement("
            UPDATE employees
               SET surname = TRIM(SUBSTRING_INDEX(name, ' ', -1)),
                   name    = TRIM(SUBSTRING(name, 1, LENGTH(name) - LENGTH(SUBSTRING_INDEX(name, ' ', -1))))
             WHERE name LIKE '% %'
        ");

        // `gender` keeps its existing values — 'other' is mapped to the register's
        // "T" column when Form A is rendered.
        //
        // doctrine/dbal cannot ->change() an enum column, so this goes through raw
        // SQL. Verified against live data: no rows use 'daily_wage'.
        DB::statement("ALTER TABLE employees MODIFY employee_type
            ENUM('permanent','probationary','temporary','contract','apprentice','fixed_term','casual')
            NOT NULL DEFAULT 'permanent'");
    }

    /**
     * Reverse the migrations.
     *
     * Note: the name/surname split is not reversed — recombining would guess at
     * the original spacing.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['skill_category_id']);
            $table->dropUnique(['pan']);
            $table->dropUnique(['aadhaar_hash']);
            $table->dropIndex(['date_of_exit']);

            $table->dropColumn([
                'surname',
                'nationality',
                'education_level',
                'identification_mark',
                'skill_category_id',
                'pan',
                'aadhaar_number',
                'aadhaar_last4',
                'aadhaar_hash',
                'permanent_address',
                'service_book_no',
                'date_of_exit',
                'reason_for_exit',
                'photo_path',
                'signature_path',
                'remarks',
            ]);
        });

        DB::statement("ALTER TABLE employees MODIFY employee_type
            ENUM('permanent','daily_wage') NOT NULL DEFAULT 'permanent'");
    }
};
