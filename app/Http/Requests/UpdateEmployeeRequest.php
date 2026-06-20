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

               'department_id' => 'nullable|exists:departments,id',

               'designation_id' => 'nullable|exists:roles,id',

               'site_id' => 'nullable|exists:sites,id',

               'supervisor_id' => 'nullable|exists:employees,id',

               'status' => 'in:0,1',

               'relay_shift' => 'nullable|in:general,relay_1,relay_2,relay_3',


          ];
     }
}
