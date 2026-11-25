<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Penalty extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',

        // Current/editable register data
        'penalty_date',
        'month',
        'year',
        'recovery_type',
        'reason',
        'particulars',
        'amount',
        'show_cause_issued',
        'explanation_heard_in_presence',
        'number_of_installments',
        'installment_amount',
        'first_month',
        'first_year',
        'last_month',
        'last_year',
        'date_of_complete_recovery',
        'remarks',

        // IMPORTANT:
        // Do not allow these to be changed through normal
        // request/update/import operations.
        //
        // They are populated internally when creating
        // the penalty.
        'calculation_amount',
        'calculation_recovery_type',
        'calculation_particulars',
        'calculation_date',
        'calculation_number_of_installments',
        'calculation_first_month',
        'calculation_first_year',
        'calculation_last_month',
        'calculation_last_year',
    ];

    protected $casts = [
        'penalty_date' => 'date',
        'amount' => 'decimal:2',

        'month' => 'integer',
        'year' => 'integer',

        'show_cause_issued' => 'boolean',

        'number_of_installments' => 'integer',
        'installment_amount' => 'decimal:2',

        'first_month' => 'integer',
        'first_year' => 'integer',

        'last_month' => 'integer',
        'last_year' => 'integer',

        'date_of_complete_recovery' => 'date',

        'calculation_amount' => 'decimal:2',
        'calculation_date' => 'date',

        'calculation_number_of_installments' => 'integer',
        'calculation_first_month' => 'integer',
        'calculation_first_year' => 'integer',
        'calculation_last_month' => 'integer',
        'calculation_last_year' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
    public function loanInstallments()
    {
        return $this->hasMany(
            LoanRecoveryInstallment::class
        );
    }
}
