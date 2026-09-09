<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Form B (wage register) is headed by the rate of minimum wages "and since
     * the date", so a rate is a dated revision rather than a single value: one
     * row per skill category per effective_from, and the row in force on a date
     * is the latest one not after it. Older rows are kept so a register printed
     * for a past month still shows the rates that applied then.
     *
     * Rates are establishment-wide — not per site — see the API notes.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('employee_wages', function (Blueprint $table) {
            $table->id();

            $table->enum('skill_category', [
                'highly_skilled',
                'skilled',
                'semi_skilled',
                'unskilled',
            ]);

            $table->decimal('minimum_basic', 12, 2)->default(0);
            $table->decimal('dearness_allowance', 12, 2)->default(0);

            // Money per overtime hour, not a multiplier of basic.
            $table->decimal('overtime_rate', 12, 2)->default(0);

            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One rate per category per revision date.
            $table->unique(['skill_category', 'effective_from'], 'employee_wages_category_effective_unique');

            // Drives the "rate in force on date X" lookup.
            $table->index(['skill_category', 'effective_from', 'is_active'], 'employee_wages_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('employee_wages');
    }
};
