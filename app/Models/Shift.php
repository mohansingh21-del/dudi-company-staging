<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;
    protected $fillable = [
        'shift_name',
        'start_time',
        'end_time',
        'minimum_working_hours',
        'is_night_shift'
    ];

    public function shiftAssignments()
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }

    public function shiftPlans()
    {
        return $this->hasMany(ShiftPlan::class, 'shift_id');
    }
}
