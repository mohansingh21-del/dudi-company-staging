<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class AttendanceResource extends JsonResource
{
    public function toArray($request)
    {
        return [

            'id' => $this->id,

            'employee_id' => $this->employee_id,
            'shift_id' => $this->shift_id,

            'employee_name' => optional($this->employee)->name,
            'employee_code' => optional($this->employee)->employee_code,
            'shift_name' => optional($this->shift)->shift_name,

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
            'remarks' => $this->remarks,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,


        ];
    }
}
