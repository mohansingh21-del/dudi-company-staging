<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateDesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $designationId = $this->route('role');

        return [

            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->ignore($designationId)
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Designation name already exists.',
            'name.required' => 'Designation name is required.',
            'name.max' => 'Designation name cannot be longer than 255 characters.',
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
