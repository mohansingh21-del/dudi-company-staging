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

            'surname' => 'nullable|string|max:255',

            'father_name' => 'nullable|string|max:255',

            'dob' => 'nullable|date_format:d/m/Y',

            'nationality' => 'nullable|string|max:100',

            'education_level' => 'nullable|string|max:255',

            'identification_mark' => 'nullable|string|max:255',

            'gender' => 'nullable|in:male,female,other',

            'mobile' => 'nullable|string|max:15|unique:employees,mobile',

            'address' => 'nullable|string',

            'permanent_address' => 'nullable|string',

            'emergency_contact' => 'nullable|string|max:15',

            'joining_date' => 'required|date_format:d/m/Y',

            'service_book_no' => 'nullable|string|max:255',

            'employee_type' => 'nullable|in:permanent,probationary,temporary,contract,apprentice,fixed_term,casual',

            'department_id' => 'nullable|exists:departments,id',

            'designation_id' => 'nullable|exists:roles,id',

            'skill_category' => 'nullable|in:highly_skilled,skilled,semi_skilled,unskilled',

            'site_id' => 'nullable|exists:sites,id',

            'supervisor_id' => 'nullable|exists:employees,id',

            'relay_id' => 'nullable|exists:relays,id',

            'date_of_exit' => 'nullable|date_format:d/m/Y|after_or_equal:joining_date',

            'reason_for_exit' => 'nullable|required_with:date_of_exit|string|max:255',

            'photo' => 'nullable|image|mimes:jpeg,jpg,png|max:2048',

            'signature' => 'nullable|image|mimes:jpeg,jpg,png|max:2048',

            'remarks' => 'nullable|string',

            'status' => 'in:0,1',
        ];
    }

    public function messages(): array
    {
        return [
            'reason_for_exit.required_with' => 'Reason for exit is required when a date of exit is given.',
            'date_of_exit.after_or_equal' => 'Date of exit cannot be before the joining date.',
        ];
    }
}
