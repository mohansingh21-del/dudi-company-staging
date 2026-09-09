<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendanceRequest extends FormRequest
{

     public function authorize(): bool
     {
          return true;
     }

     public function rules()
     {
          return [
               'employee_id' => 'nullable|exists:employees,id',
               'date' => 'nullable|date_format:Y-m-d',
               'check_in' => 'nullable|date_format:H:i',
               'check_out' => 'nullable|date_format:H:i',
               'attendance_status' => 'required|in:present,absent,half_day,leave,rest_day,exception',
               // Which Form E block the day is booked against. Required only when
               // the status is 'leave'; 'rest_day' always maps to Compensatory Rest.
               'leave_type_id' => 'required_if:attendance_status,leave|nullable|integer|exists:leave_types,id',
               'site_id' => 'nullable|exists:sites,id',
               'place_of_work' => 'nullable|in:underground,opencast,surface',
               'remarks' => 'nullable|string'
          ];
     }
}
