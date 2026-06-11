<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{

     public function authorize(): bool
     {
          return true;
     }

     public function rules(): array
     {
          return [

               'name' => 'required|string|max:255',

               'father_name' => 'nullable|string|max:255',

               'dob' => 'nullable|date_format:d/m/Y',

               'gender' => 'nullable|in:male,female,other',

               'mobile' => 'nullable|string|max:15|unique:employees,mobile,' . $this->employee,

               'address' => 'nullable|string',

               'emergency_contact' => 'nullable|string|max:15',

               'joining_date' => 'required|date_format:d/m/Y',

               'employee_type' => 'required|in:permanent,daily_wage',

               'department_id' => 'nullable|exists:departments,id',

               'designation_id' => 'nullable|exists:roles,id',

               'site_id' => 'nullable|exists:sites,id',

               'supervisor_id' => 'nullable|exists:employees,id',

               'salary_type' => 'required|in:monthly,daily_wage',

               'basic_salary' => 'nullable|numeric|min:0',

               'daily_wage' => 'nullable|numeric|min:0',

               'pf_applicable' => 'nullable|boolean',

               'pf_number' => 'nullable|string|max:255',

               'bank_name' => 'nullable|string|max:255',

               'bank_account_number' => 'nullable|string|max:50',

               'ifsc_code' => 'nullable|string|max:20',

               'mess_deduction_applicable' => 'nullable|boolean',

               'status' => 'in:0,1',

               'other_deduction_appliacble' => 'nullable|boolean',

               'other_deduction' => 'nullable|numeric|min:0',
               
               'relay_shift' => 'nullable|in:general,relay_1,relay_2,relay_3',
               'pf_amount' => 'nullable|numeric|min:0',
               'mess_deduction_amount' => 'nullable|numeric|min:0',
               'rest_days' => 'nullable|integer|min:0|max:31',


          ];
     }
}
