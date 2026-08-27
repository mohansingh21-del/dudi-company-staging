<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class AttendanceResource extends JsonResource
{
    public function toArray($request)
    {
        $leaveType = \App\Services\AttendanceLeaveSync::leaveTypeForDay($this->employee_id, $this->date);

        return [

            'id' => $this->id,

            'employee_id' => $this->employee_id,
            'shift_id' => $this->shift_id,

            'employee_name' => optional($this->employee)->name,
            'employee_code' => optional($this->employee)->employee_code,

            // Site and relay live on the employee, not on the attendance row.
            // Kept in step with the keys index() returns so both endpoints
            // feed the same shape to the frontend.
            'site_id' => optional($this->employee)->site_id,
            'site_name' => optional(optional($this->employee)->site)->site_name,
            'relay_id' => optional($this->employee)->relay_id,
            'relay_name' => optional(optional($this->employee)->relay)->name,

            'shift_name' => optional($this->shift)->shift_name,

            'place_of_work' => $this->place_of_work,
            'place_of_work_label' => $this->place_of_work_label,

            'date' => $this->date
                ? Carbon::parse($this->date)->format('d M Y')
                : null,

            'check_in' => $this->check_in
                ? Carbon::parse($this->check_in)->format('H:i')
                : null,

            'check_out' => $this->check_out
                ? Carbon::parse($this->check_out)->format('H:i')
                : null,

            'working_hours' => $this->working_hours,
            'late_minutes' => $this->late_minutes,
            'early_exit_minutes' => $this->early_exit_minutes,

            'attendance_status' => $this->attendance_status,
            'attendance_status_label' => $this->attendance_status
                ? ucwords(str_replace('_', ' ', $this->attendance_status))
                : null,

            // Leave type lives on the backing `leaves` row, not on this table.
            // Null for present/absent/half_day.
            'leave_type_id' => $leaveType['leave_type_id'],
            'leave_type_name' => $leaveType['leave_type_name'],

            'remarks' => $this->remarks,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,


        ];
    }
}
