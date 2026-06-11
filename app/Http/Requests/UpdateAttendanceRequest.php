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
               'check_in' => 'required|date_format:H:i',
               'check_out' => 'required|date_format:H:i',
               'attendance_status' => 'required|in:present,absent,half_day,leave',
               'remarks' => 'nullable|string'
          ];
     }
}
