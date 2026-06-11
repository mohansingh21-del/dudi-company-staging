<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_id',
        'title',
        'amount',
        'type', // allowance / deduction
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Relationship: PayrollItem belongs to a Payroll
     */
    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }
}
