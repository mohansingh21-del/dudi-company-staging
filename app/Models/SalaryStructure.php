<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryStructure extends Model
{
    use HasFactory;

    protected $table = 'salary_structures';

    protected $fillable = [
        'designation_id',
        'basic_salary',
        'shift_allowance',
        'incentives',
        'pf_applicable',
        'mess_deduction_applicable',
        'other_deduction',
        'is_active',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'shift_allowance' => 'decimal:2',
        'incentives' => 'decimal:2',
        'other_deduction' => 'decimal:2',
        'pf_applicable' => 'boolean',
        'mess_deduction_applicable' => 'boolean',
    ];

    /**
     * To calculate gross salary.
     */
    public function getGrossSalaryAttribute()
    {
        return $this->basic_salary
            + $this->shift_allowance
            + $this->incentives;
    }
    public function designation()
    {
        return $this->belongsTo(Role::class, 'designation_id');
    }
}
