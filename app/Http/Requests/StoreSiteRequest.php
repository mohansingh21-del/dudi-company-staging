<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:sites,site_name',
            'address' => 'nullable|string|max:5000'
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Site name is required.',
            'name.unique' => 'Site name already exists.',
            'name.max' => 'Site name cannot be longer than 255 characters.',
            'address.max' => 'Address cannot be longer than 5000 characters.',
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
