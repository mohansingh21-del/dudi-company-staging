<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateEquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $equipmentId = $this->route('equipment');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('equipments', 'name')->ignore($equipmentId)
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Equipment is required.',
            'name.unique' => 'Equipment already exists.',
            'name.max' => 'Equipment name cannot be longer than 255 characters.',
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
