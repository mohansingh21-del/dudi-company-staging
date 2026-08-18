<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = ['role_user_id', 'employee_code', 'name', 'surname', 'father_name', 'dob', 'nationality', 'education_level', 'identification_mark', 'gender', 'mobile', 'address', 'permanent_address', 'emergency_contact', 'joining_date', 'service_book_no', 'employee_type', 'department_id', 'designation_id', 'skill_category', 'site_id', 'place_of_employment', 'supervisor_id', 'is_active', 'date_of_exit', 'reason_for_exit', 'photo_path', 'signature_path', 'remarks', 'relay_id'];

    protected $casts = [
        'dob' => 'date:Y-m-d',
        'joining_date' => 'date:Y-m-d',
        'date_of_exit' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function relay()
    {
        return $this->belongsTo(Relay::class, 'relay_id');
    }

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
        // 1. Check individual override for this employee on this date
        $override = EmployeeShiftOverride::getForDate($this->id, $dateStr);
        if ($override) {
            return $override->shift_id;
        }

        // 2. Check relay shift mapping for this date (Option B: relay-based lookup)
        if ($this->relay_id && $this->relay && $this->relay->is_rotating) {
            $mapping = RelayShiftMapping::getForDate($this->relay_id, $dateStr);
            if ($mapping) {
                return $mapping->shift_id;
            }

            // Fallback: if no mapping for the date, and date is today, use latest mapping
            if ($dateStr === now()->toDateString()) {
                $latestMapping = RelayShiftMapping::getLatestForRelay($this->relay_id);
                if ($latestMapping) {
                    return $latestMapping->shift_id;
                }
            }
        }

        // 3. Fallback to legacy employee_shift_assignments (for historical data before relay mappings)
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
        // 1. Check individual override for today
        $override = EmployeeShiftOverride::getForDate($this->id, now()->toDateString());
        if ($override) {
            return $override->shift_id;
        }

        // 2. Fallback to relay shift mapping for current week
        if ($this->relay_id) {
            $mapping = RelayShiftMapping::getForDate($this->relay_id, now()->toDateString());
            if ($mapping) {
                return $mapping->shift_id;
            }

            // Fallback: If no mapping for today, use the latest relay mapping
            $latestMapping = RelayShiftMapping::getLatestForRelay($this->relay_id);
            if ($latestMapping) {
                return $latestMapping->shift_id;
            }
        }

        // 3. Fallback to legacy current shift assignment
        $assignmentShiftId = optional($this->currentShiftAssignment)->shift_id;
        if ($assignmentShiftId) {
            return $assignmentShiftId;
        }

        return null;
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

    /**
     * The employee's payroll configuration, active or not. One row per employee
     * is enforced on write, so the latest is the only one.
     */
    public function employeePayroll()
    {
        return $this->hasOne(EmployeePayroll::class)->latestOfMany();
    }

    public function attendanceProcesseds()
    {
        return $this->hasMany(AttendanceProcessed::class);
    }

    /**
     * Full name as it appears in the Employee Register.
     */
    public function getFullNameAttribute()
    {
        return trim($this->name . ' ' . $this->surname);
    }

    /**
     * Skill classification as it reads in the Employee Register,
     * e.g. semi_skilled => "Semi-Skilled".
     */
    public function getSkillCategoryLabelAttribute()
    {
        if (!$this->skill_category) {
            return null;
        }

        return ucwords(str_replace('_', '-', $this->skill_category), '-');
    }

    /**
     * Place of employment as it reads in the register, e.g. "Opencast".
     */
    public function getPlaceOfEmploymentLabelAttribute()
    {
        if (!$this->place_of_employment) {
            return null;
        }

        return ucfirst($this->place_of_employment);
    }
}
