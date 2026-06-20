<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\Leave;
class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'required|exists:employees,id',
            'leave_type_id' => 'nullable|exists:leave_types,id',

            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',

            'reason' => 'nullable|string',

            'status' => 'in:pending,approved,rejected',
            'approved_by' => 'nullable|exists:users,id',
        ];
    }
public function withValidator($validator)
{
    $validator->after(function ($validator) {

        if (
            !$this->employee_id ||
            !$this->from_date ||
            !$this->to_date
        ) {
            return;
        }

        $leaveExists = Leave::where(
            'employee_id',
            $this->employee_id
        )
        ->whereDate('from_date', $this->from_date)
        ->whereDate('to_date', $this->to_date)
        ->exists();

        if ($leaveExists) {

            $validator->errors()->add(
                'from_date',
                'A leave already exists for this employee with the same From Date and To Date.'
            );
        }
    });
}
    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status' => 422,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422));
    }
}
