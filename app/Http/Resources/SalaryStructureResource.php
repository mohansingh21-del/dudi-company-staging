<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryStructureResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'designation' => optional($this->designation)->name,
            'basic_salary' => $this->basic_salary,
            'shift_allowance' => $this->shift_allowance,
            'incentives' => $this->incentives,
            'pf_applicable' => $this->pf_applicable,
            'mess_deduction_applicable' => $this->mess_deduction_applicable,
            'other_deduction' => $this->other_deduction,
            'status' => $this->is_active,
        ];
    }
}
