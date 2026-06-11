<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{

    public function toArray($request)
    {
        return [

            'id' => $this->id,

            'employee_code' => $this->employee_code,
            'name' => $this->name,
            'father_name' => $this->father_name,
            'dob' => optional($this->dob)->format('d F Y'),
            'gender' => $this->gender,

            'mobile' => $this->mobile,
            'emergency_contact' => $this->emergency_contact,

            'address' => $this->address,

            'joining_date' => optional($this->joining_date)->format('d F Y'),
            'employee_type' => $this->employee_type,

            'salary_type' => $this->salary_type,
            'basic_salary' => $this->basic_salary,
            'daily_wage' => $this->daily_wage,

            'pf_applicable' => $this->pf_applicable,
            'pf_number' => $this->pf_number,

            'bank_name' => $this->bank_name,
            'bank_account_number' => $this->bank_account_number,
            'ifsc_code' => $this->ifsc_code,

            'mess_deduction_applicable' => $this->mess_deduction_applicable,
            'other_deduction_appliacble' => $this->other_deduction_appliacble,
            'other_deduction' => $this->other_deduction,

            'status' => $this->is_active,

            'department' => optional($this->department)->name,
            'designation' => optional($this->designation)->name,
            'site' => optional($this->site)->site_name,
            'supervisor' => optional($this->supervisor)->name,
            'shift_id' => $this->shift_id,
            'shift' => optional(optional($this->currentShiftAssignment)->shift)->shift_name,
            'relay_shift' => $this->relay_shift,
            'pf_amount' => $this->pf_amount,
            'mess_deduction_amount' => $this->mess_deduction_amount,
            'rest_days' => $this->rest_days,
        ];
    }
}
