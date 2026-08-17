<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {

            /*
             * Current / editable recovery register fields
             */

            $table->string('recovery_type', 30)
                ->default('fine')
                ->after('employee_id');

            $table->text('particulars')
                ->nullable()
                ->after('recovery_type');

            $table->boolean('show_cause_issued')
                ->default(false)
                ->after('amount');

            $table->string('explanation_heard_in_presence')
                ->nullable()
                ->after('show_cause_issued');

            $table->unsignedInteger('number_of_installments')
                ->nullable()
                ->after('explanation_heard_in_presence');

            $table->unsignedTinyInteger('first_month')
                ->nullable()
                ->after('number_of_installments');

            $table->unsignedSmallInteger('first_year')
                ->nullable()
                ->after('first_month');

            $table->unsignedTinyInteger('last_month')
                ->nullable()
                ->after('first_year');

            $table->unsignedSmallInteger('last_year')
                ->nullable()
                ->after('last_month');

            $table->date('date_of_complete_recovery')
                ->nullable()
                ->after('last_year');

            $table->text('remarks')
                ->nullable()
                ->after('date_of_complete_recovery');


            /*
             * IMMUTABLE PAYROLL / CALCULATION SNAPSHOT
             *
             * These values are populated only when the penalty
             * is first created.
             */

            $table->decimal('calculation_amount', 12, 2)
                ->nullable()
                ->after('remarks');

            $table->string('calculation_recovery_type', 30)
                ->nullable()
                ->after('calculation_amount');

            $table->text('calculation_particulars')
                ->nullable()
                ->after('calculation_recovery_type');

            $table->date('calculation_date')
                ->nullable()
                ->after('calculation_particulars');

            $table->unsignedInteger('calculation_number_of_installments')
                ->nullable()
                ->after('calculation_date');

            $table->unsignedTinyInteger('calculation_first_month')
                ->nullable()
                ->after('calculation_number_of_installments');

            $table->unsignedSmallInteger('calculation_first_year')
                ->nullable()
                ->after('calculation_first_month');

            $table->unsignedTinyInteger('calculation_last_month')
                ->nullable()
                ->after('calculation_first_year');

            $table->unsignedSmallInteger('calculation_last_year')
                ->nullable()
                ->after('calculation_last_month');
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {

            $table->dropColumn([
                'recovery_type',
                'particulars',
                'show_cause_issued',
                'explanation_heard_in_presence',
                'number_of_installments',
                'first_month',
                'first_year',
                'last_month',
                'last_year',
                'date_of_complete_recovery',
                'remarks',

                'calculation_amount',
                'calculation_recovery_type',
                'calculation_particulars',
                'calculation_date',
                'calculation_number_of_installments',
                'calculation_first_month',
                'calculation_first_year',
                'calculation_last_month',
                'calculation_last_year',
            ]);
        });
    }
};
