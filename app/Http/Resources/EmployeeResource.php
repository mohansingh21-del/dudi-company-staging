<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{

    /**
     * Identity only. Salary, statutory identifiers (PAN, Aadhaar, UAN, ESIC IP,
     * LWF) and bank details are served by the employee-payroll endpoints.
     */
    public function toArray($request)
    {
        return [

            'id' => $this->id,

            'employee_code' => $this->employee_code,
            'name' => $this->name,
            'surname' => $this->surname,
            'full_name' => $this->full_name,
            'father_name' => $this->father_name,
            'dob' => optional($this->dob)->format('d F Y'),
            'gender' => $this->gender,
            'nationality' => $this->nationality,
            'education_level' => $this->education_level,
            'identification_mark' => $this->identification_mark,

            'mobile' => $this->mobile,
            'emergency_contact' => $this->emergency_contact,

            'address' => $this->address,
            'permanent_address' => $this->permanent_address,

            'joining_date' => optional($this->joining_date)->format('d F Y'),
            'service_book_no' => $this->service_book_no,
            'employee_type' => $this->employee_type,

            'status' => $this->is_active,
            'date_of_exit' => optional($this->date_of_exit)->format('d F Y'),
            'reason_for_exit' => $this->reason_for_exit,

            'department' => optional($this->department)->name,
            'designation' => optional($this->designation)->name,
            'skill_category' => $this->skill_category,
            'skill_category_label' => $this->skill_category_label,
            'site' => optional($this->site)->site_name,
            'supervisor' => optional($this->supervisor)->name,
            'shift_id' => $this->shift_id,
            'shift' => $this->shift_id ? optional(\App\Models\Shift::find($this->shift_id))->shift_name : null,
            'relay_id' => $this->relay_id,
            'relay_shift' => optional($this->relay)->name,
            'relay_name' => optional($this->relay)->name,

            'photo_url' => $this->photo_path ? asset('storage/' . $this->photo_path) : null,
            'signature_url' => $this->signature_path ? asset('storage/' . $this->signature_path) : null,
            'remarks' => $this->remarks,
        ];
    }
}
