<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $departmentId = $this->route('shift');

        return [

            'name' => [
                'required',
                Rule::unique('shifts', 'shift_name')->ignore($departmentId)
            ],
            'start_time' => 'required',
            'end_time' => 'required',
            'minimum_working_hours' => 'nullable|numeric|min:0|max:24',
            'is_night_shift' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Shift name already exists.',
            'name.required' => 'Shift name is required.',
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
