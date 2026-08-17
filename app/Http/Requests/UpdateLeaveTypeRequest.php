<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * The four blocks are fixed, so register_group is not editable — only the
 * display name, the annual quota, and the active flag.
 */
class UpdateLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $leaveTypeId = $this->route('leavetype');

        return [
            'name' => [
                'sometimes',
                'required',
                Rule::unique('leave_types', 'name')->ignore($leaveTypeId)
            ],

            // allowed_days is NOT NULL, so this has to be validated as a number
            // rather than passed straight through.
            'Annual_limit' => 'sometimes|nullable|integer|min:0|max:365',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Leave type name already exists.',
            'name.required' => 'Leave type name is required.',
            'Annual_limit.integer' => 'Annual limit must be a whole number of days.',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422)
        );
    }
}
