<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeShiftAssignmentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => optional($this->employee)->name,
            'employee_code' => optional($this->employee)->employee_code,
            'shift_id' => $this->shift_id,
            'shift_name' => optional($this->shift)->shift_name,
            'start_time' => optional($this->shift)->start_time,
            'end_time' => optional($this->shift)->end_time,
            'from_date' => $this->from_date,
            'to_date' => $this->to_date,
            'rotation_group' => $this->rotation_group,
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}
