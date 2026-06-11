<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use HasFactory;
    protected $fillable = [
        'employee_id',
        'month',
        'year',
        'total_days',
        'present_days',
        'basic_salary',
        'gross_salary',
        'deductions',
        'net_salary'
    ];
}
