<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Leave extends Model
{
    use HasFactory;
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'from_date',
        'to_date',
        'reason',
        'status',
        'approved_by',
    ];
    public function employee()
{
    return $this->belongsTo(Employee::class);
}

public function leaveType()
{
    return $this->belongsTo(LeaveType::class);
}
public static function hasApprovedLeave($employeeId, $date)
    {
        return self::where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->exists();
    }
public function approvedBy()
{
    return $this->belongsTo(User::class, 'approved_by');
}
public function approver()
{
    return $this->belongsTo(User::class, 'approved_by');
}
}
