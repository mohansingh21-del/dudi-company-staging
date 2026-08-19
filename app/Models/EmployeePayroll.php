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

        'uan',

        'esic_ip_number',

        'lwf_number_applicable',

        'lwf_number',

        'pan',

        'aadhaar_number',

        'bank_name',

        'bank_account_number',

        'ifsc_code',

        'mess_deduction_applicable',

        'other_deduction_appliacble',

        'other_deduction',

        'rest_days',

        'effective_from',
        'pf_amount',
        'mess_deduction_amount',
        'rest_days',
        'is_active'
    ];

    protected $casts = [

        'pf_applicable' => 'boolean',

        'lwf_number_applicable' => 'boolean',

        'mess_deduction_applicable' => 'boolean',

        'other_deduction_appliacble' => 'boolean',

        'rest_days' => 'integer',

        'effective_from' => 'date',

        'aadhaar_number' => 'encrypted'
    ];

    protected $hidden = ['aadhaar_number', 'aadhaar_hash'];

    /**
     * Aadhaar is encrypted non-deterministically, so it cannot be searched or
     * checked for duplicates. Writing it also maintains a SHA-256 hash (unique,
     * for duplicate detection) and the last four digits (for display).
     */
    public function setAadhaarNumberAttribute($value)
    {
        $digits = $value === null ? null : preg_replace('/\D/', '', $value);

        if ($digits === null || $digits === '') {
            $this->attributes['aadhaar_number'] = null;
            $this->attributes['aadhaar_last4']  = null;
            $this->attributes['aadhaar_hash']   = null;

            return;
        }

        // encrypt() serializes by default; the `encrypted` cast decrypts without
        // unserializing, so the second argument has to be false to match it.
        $this->attributes['aadhaar_number'] = encrypt($digits, false);
        $this->attributes['aadhaar_last4']  = substr($digits, -4);
        $this->attributes['aadhaar_hash']   = hash('sha256', $digits);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
    public function department()
{
    return $this->belongsTo(Department::class);
}
}
