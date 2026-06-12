<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeePayroll extends Model
{
    use HasFactory;
       protected $fillable = [

        'employee_id',

        'salary_type',

        'basic_salary',

        'daily_wage',

        'pf_applicable',

        'pf_number',

        'bank_name',

        'bank_account_number',

        'ifsc_code',

        'mess_deduction_applicable',

        'other_deduction_appliacble',

        'other_deduction',

        'rest_days',

        'effective_from',

        'is_active'
    ];

    protected $casts = [

        'pf_applicable' => 'boolean',

        'mess_deduction_applicable' => 'boolean',

        'other_deduction_appliacble' => 'boolean',

        'rest_days' => 'integer',

        'effective_from' => 'date'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
    public function department()
{
    return $this->belongsTo(Department::class);
}
}
