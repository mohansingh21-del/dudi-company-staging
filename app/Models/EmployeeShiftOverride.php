<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeShiftOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'effective_from',
        'effective_until',
        'shift_id',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_until' => 'date:Y-m-d',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the active override for an employee on a specific date.
     */
    public static function getForDate($employeeId, $dateStr)
    {
        return static::where('employee_id', $employeeId)
            ->where('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $dateStr);
            })
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->first();
    }
}
