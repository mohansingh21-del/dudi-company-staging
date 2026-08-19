<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\Leave;
use App\Models\LeaveType;
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

            // Required: a leave with no type counts toward nothing on the Form E
            // register, so it would save cleanly and then silently disappear.
            'leave_type_id' => 'required|exists:leave_types,id',

            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',

            'reason' => 'nullable|string',

            'status' => 'in:pending,approved,rejected',
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

        // A paid block with no annual quota configured has nothing to draw
        // against, so the leave is refused here rather than saved against an
        // entitlement of zero that the register would silently floor away.
        // Unpaid leave is uncapped and never gated.
        if ($this->leave_type_id) {

            $leaveType = LeaveType::find($this->leave_type_id);

            if ($leaveType && ! $leaveType->canApply()) {

                $validator->errors()->add(
                    'leave_type_id',
                    $leaveType->quotaMissingMessage()
                );
            }
        }

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
