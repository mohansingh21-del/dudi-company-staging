<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeProductAssignmentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => optional($this->employee)->name,
            'product_id' => $this->product_id,
            'product_name' => optional($this->product)->name,
            'site_id' => $this->site_id,
            'site_name' => optional($this->site)->name,
            'department_id' => $this->department_id,
            'department_name' => optional($this->department)->name,
            'quantity' => (float) $this->quantity,
            'issued_date' => optional($this->issued_date)->format('Y-m-d'),
            'remarks' => $this->remarks,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
