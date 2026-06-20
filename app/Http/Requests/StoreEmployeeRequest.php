<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'employee_code' => 'required|string|max:255|unique:employees,employee_code',

            'name' => 'required|string|max:255',

            'father_name' => 'nullable|string|max:255',

            'dob' => 'nullable|date_format:d/m/Y',

            'gender' => 'nullable|in:male,female,other',

            'mobile' => 'nullable|string|max:15|unique:employees,mobile',

            'address' => 'nullable|string',

            'emergency_contact' => 'nullable|string|max:15',

            'joining_date' => 'required|date_format:d/m/Y',

            'department_id' => 'nullable|exists:departments,id',

            'designation_id' => 'nullable|exists:roles,id',

            'site_id' => 'nullable|exists:sites,id',

            'supervisor_id' => 'nullable|exists:employees,id',
            
            'relay_shift' => 'nullable|in:general,relay_1,relay_2,relay_3',

            'status' => 'in:0,1',
        ];
    }
}
