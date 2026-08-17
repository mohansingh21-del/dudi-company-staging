<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeShiftAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'shift_id',
        'from_date',
        'to_date',
        'rotation_group'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    protected static function booted()
    {
        static::creating(function ($assignment) {
            if (empty($assignment->from_date)) {
                $assignment->from_date = now()->toDateString();
            }
        });

        static::created(function ($assignment) {
            // Find the previous shift assignment (second latest)
            $previous = EmployeeShiftAssignment::where('employee_id', $assignment->employee_id)
                ->where('id', '!=', $assignment->id)
                ->orderBy('id', 'desc')
                ->first();

            $oldShiftId = $previous ? $previous->shift_id : null;

            // Only log if it's a new assignment or the shift has changed
            if ($oldShiftId !== $assignment->shift_id) {
                EmployeeShiftHistory::create([
                    'employee_id' => $assignment->employee_id,
                    'old_shift_id' => $oldShiftId,
                    'new_shift_id' => $assignment->shift_id,
                    'changed_by' => app()->runningInConsole() ? 'roster' : 'manual',
                    'change_date' => $assignment->from_date ?? now()->toDateString(),
                    'user_id' => auth()->id() ?? null,
                ]);
            }
        });

        static::updated(function ($assignment) {
            // Log history if the shift_id changes on update
            if ($assignment->isDirty('shift_id')) {
                $oldShiftId = $assignment->getOriginal('shift_id');

                EmployeeShiftHistory::create([
                    'employee_id' => $assignment->employee_id,
                    'old_shift_id' => $oldShiftId,
                    'new_shift_id' => $assignment->shift_id,
                    'changed_by' => app()->runningInConsole() ? 'roster' : 'manual',
                    'change_date' => $assignment->from_date ?? now()->toDateString(),
                    'user_id' => auth()->id() ?? null,
                ]);
            }
        });
    }
}
