<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Penalty extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'penalty_date',
        'month',
        'year',
        'reason',
        'amount',
    ];

    protected $casts = [
        'penalty_date' => 'date',
        'amount' => 'decimal:2',
        'month' => 'integer',
        'year' => 'integer',
    ];

    /**
     * Relationship: Penalty belongs to an Employee
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
