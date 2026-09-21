<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateTrainingTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $departmentId = $this->route('trainingtype');

        return [

            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('training_types', 'name')->ignore($departmentId)
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Training Type already exists.',
            'name.required' => 'Training Type is required.',
            'name.max' => 'Training Type cannot be longer than 255 characters.',
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
