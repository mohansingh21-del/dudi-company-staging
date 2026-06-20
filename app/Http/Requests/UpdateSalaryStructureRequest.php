<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateSalaryStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $departmentId = $this->route('salarystructure');

        return [

            'designation_id' => [
                'required',
                Rule::unique('salary_structures', 'designation_id')->ignore($departmentId)
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'designation_id.unique' => 'Salary Structute designation already exists.',
            'designation_id.required' => 'Salary Structute designation is required.',
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
