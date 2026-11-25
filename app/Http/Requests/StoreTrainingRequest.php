<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'training_name' => 'required|string|max:255',

            // Only an active type can be scheduled against; an inactive one is
            // retired and must not appear on new trainings.
            'training_type_id' => [
                'required',
                Rule::exists('training_types', 'id')->where('is_active', 1),
            ],

            'supervisor_id' => [
                'required',
                Rule::exists('employees', 'id')->where('is_active', 1),
            ],

            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',

            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => [
                'required',
                'distinct',
                Rule::exists('employees', 'id')->where('is_active', 1),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'training_type_id.exists' => 'The selected training type is not available.',
            'supervisor_id.exists' => 'The selected supervisor is not an active employee.',
            'employee_ids.required' => 'Enroll at least one employee.',
            'employee_ids.*.exists' => 'One or more selected employees are not active.',
            'employee_ids.*.distinct' => 'The same employee cannot be enrolled twice.',
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
