<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = ['role_user_id', 'employee_code', 'name', 'father_name', 'dob', 'gender', 'mobile', 'address', 'emergency_contact', 'joining_date', 'employee_type', 'department_id', 'designation_id', 'site_id', 'supervisor_id', 'salary_type', 'basic_salary', 'pf_applicable', 'pf_number', 'bank_name', 'bank_account_number', 'ifsc_code', 'mess_deduction_applicable', 'other_deduction_appliacble', 'other_deduction', 'is_active', 'relay_shift', 'pf_amount', 'mess_deduction_amount', 'rest_days'];

    protected $casts = [
        'dob' => 'date:Y-m-d',
        'joining_date' => 'date:Y-m-d',
    ];
    public function roleUser()
    {
        return $this->belongsTo(RoleUser::class, 'role_user_id');
    }
    public function department()
    {
        return $this->belongsTo(Department::class);
    }
    public function designation()
    {
        return $this->belongsTo(Role::class);
    }
    public function site()
    {
        return $this->belongsTo(Site::class);
    }
    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function vehicleMappings()
    {
        return $this->hasMany(VehicleDriverMapping::class, 'driver_id');
    }

    public function shiftAssignments()
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }

    public function getShiftIdForDate($dateStr)
    {
        $assignments = $this->shiftAssignments()->get();

        $assignment = $assignments->filter(function ($assign) use ($dateStr) {
            $from = $assign->from_date ?: ($assign->created_at ? $assign->created_at->toDateString() : now()->toDateString());
            $to = $assign->to_date;
            return $from && $dateStr >= $from && (is_null($to) || $dateStr <= $to);
        })->first();

        if ($assignment) {
            return $assignment->shift_id;
        }

        $nextChange = EmployeeShiftHistory::where('employee_id', $this->id)
            ->where('change_date', '>', $dateStr)
            ->orderBy('change_date', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if ($nextChange) {
            return $nextChange->old_shift_id ?: null;
        }

        $latestChange = EmployeeShiftHistory::where('employee_id', $this->id)
            ->where('change_date', '<=', $dateStr)
            ->orderBy('change_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($latestChange) {
            return $latestChange->new_shift_id;
        }

        $firstAssignment = $assignments->sortBy('id')->first();
        if ($firstAssignment) {
            $from = $firstAssignment->from_date ?: ($firstAssignment->created_at ? $firstAssignment->created_at->toDateString() : now()->toDateString());
            if ($dateStr < $from) {
                return null;
            }
        }

        $latestAssignment = $assignments->sortByDesc('id')->first();
        return $latestAssignment ? $latestAssignment->shift_id : null;
    }

    public function getShiftForDate($dateStr)
    {
        $shiftId = $this->getShiftIdForDate($dateStr);
        return $shiftId ? Shift::find($shiftId) : null;
    }

    public function shiftHistory()
    {
        return $this->hasMany(EmployeeShiftHistory::class);
    }

    public function leaves()
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    public function currentShiftAssignment()
    {
        return $this->hasOne(EmployeeShiftAssignment::class)->latestOfMany();
    }

    public function getShiftIdAttribute()
    {
        return optional($this->currentShiftAssignment)->shift_id;
    }

    public function getPreviousShiftAttribute()
    {
        $assignment = $this->shiftAssignments()
            ->orderBy('id', 'desc')
            ->skip(1)
            ->first();

        return $assignment && $assignment->shift ? $assignment->shift->shift_name : null;
    }
    public function penalties()
    {
        return $this->hasMany(Penalty::class);
    }

    public function payrolls()
    {
        return $this->hasMany(Payroll::class);
    }

    public function activePayroll()
    {
        return $this->hasOne(EmployeePayroll::class)
            ->where('is_active', true)
            ->latest();
    }

    public function attendanceProcesseds()
    {
        return $this->hasMany(AttendanceProcessed::class);
    }

    public function getRestDaysAttribute($value)
    {
        $activePayroll = $this->activePayroll;
        return ($activePayroll && $activePayroll->rest_days !== null) ? (int)$activePayroll->rest_days : (int)$value;
    }
}
