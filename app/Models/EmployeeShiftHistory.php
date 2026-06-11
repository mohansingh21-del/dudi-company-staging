<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeShiftHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'old_shift_id',
        'new_shift_id',
        'changed_by',
        'change_date',
        'user_id',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function oldShift()
    {
        return $this->belongsTo(Shift::class, 'old_shift_id');
    }

    public function newShift()
    {
        return $this->belongsTo(Shift::class, 'new_shift_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
