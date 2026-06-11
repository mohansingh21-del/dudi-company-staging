<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeShiftHistoryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => optional($this->employee)->name,
            'employee_code' => optional($this->employee)->employee_code,
            'old_shift' => optional($this->oldShift)->shift_name,
            'new_shift' => optional($this->newShift)->shift_name,
            'changed_by' => $this->changed_by,
            'change_date' => $this->change_date,
            'user_id' => $this->user_id,
            'changed_by_user' => optional($this->user)->name ?? optional($this->user)->email,
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}
