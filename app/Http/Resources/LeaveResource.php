<?php

namespace App\Http\Resources;
use Illuminate\Http\Request;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class LeaveResource extends JsonResource
{
    public function toArray($request)
    {
        $fromDate = Carbon::parse($this->from_date);
        $toDate = Carbon::parse($this->to_date);

        return [
            'id' => $this->id,

            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee->name,
            'leave_type_id' => $this->leave_type_id,
            'leave_type_name' => $this->leaveType->name,
            'from_date' => $fromDate->format('d M Y'),
            'to_date' => $toDate->format('d M Y'),

            'number_of_days' => $fromDate->diffInDays($toDate) + 1,
             'paid_leave' => optional($this->leaveType)->leave_category === 'paid' ? ($fromDate->diffInDays($toDate) + 1) : 0,
            'unpaid_leave' => optional($this->leaveType)->leave_category !== 'paid' ? ($fromDate->diffInDays($toDate) + 1) : 0,

            'reason' => $this->reason,
            'status' => $this->status,

'approved_by_role' => (
    $this->approver &&
    $this->approver->roles &&
    $this->approver->roles->count()
)
    ? $this->approver->roles->first()->name
    : null,

            'created_at' => optional($this->created_at)->format('d F Y'),
        ];
    }
}