<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use App\Models\Leave;
class UpdateLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => 'required|exists:employees,id',

            // Required: a leave with no type counts toward nothing on the Form E
            // register, so it would save cleanly and then silently disappear.
            'leave_type_id' => 'required|exists:leave_types,id',

            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',

            'reason' => 'nullable|string',

            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],

            'approved_by' => 'nullable|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'leave_type_id.required' => 'Leave type is required.',
            'leave_type_id.exists' => 'Selected leave type does not exist.',
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

        $leaveId = $this->route('leave')?->id
            ?? $this->route('leave');

        $leaveExists = Leave::where(
            'employee_id',
            $this->employee_id
        )
        ->where('id', '!=', $leaveId)
        ->where(function ($query) {

            $query->whereBetween('from_date', [
                    $this->from_date,
                    $this->to_date
                ])
                ->orWhereBetween('to_date', [
                    $this->from_date,
                    $this->to_date
                ])
                ->orWhere(function ($q) {

                    $q->where('from_date', '<=', $this->from_date)
                      ->where('to_date', '>=', $this->to_date);
                });
        })
        ->exists();

        if ($leaveExists) {

            $validator->errors()->add(
                'from_date',
                'Leave already exists or overlaps with another leave for this employee.'
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
