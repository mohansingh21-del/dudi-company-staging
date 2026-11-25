<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeWageResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,

            'skill_category' => $this->skill_category,
            'skill_category_label' => $this->skill_category_label,

            'minimum_basic' => $this->minimum_basic,
            'dearness_allowance' => $this->dearness_allowance,
            'overtime_rate' => $this->overtime_rate,

            // What payroll would assign as basic salary: minimum basic + DA.
            'basic_salary' => $this->basic_salary,

            // 12% of that basic — what payroll prefills pf_amount with.
            'pf_amount' => $this->pf_amount,

            'effective_from' => optional($this->effective_from)->toDateString(),
            'is_active' => $this->is_active,
        ];
    }
}
