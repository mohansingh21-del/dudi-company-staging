<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanRecoveryInstallment extends Model
{
    use HasFactory;

    protected $fillable = [
        'penalty_id',
        'employee_id',
        'month',
        'year',
        'installment_number',
        'opening_balance',
        'salary_basis',
        'maximum_allowed_amount',
        'installment_amount',
        'closing_balance',
        'status',
        'payroll_id',
        'deduction_date',
        'remarks',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'installment_number' => 'integer',

        'opening_balance' => 'decimal:2',
        'salary_basis' => 'decimal:2',
        'maximum_allowed_amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'closing_balance' => 'decimal:2',

        'deduction_date' => 'date',
    ];

    public function penalty()
    {
        return $this->belongsTo(Penalty::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }
}
