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
            'name'    => 'required|string|max:255|unique:sites,site_name',
            'address' => 'nullable|string|max:65535',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'   => 'Site name is required.',
            'name.string'     => 'Site name must be a valid text value.',
            'name.max'        => 'Site name may not be greater than 255 characters.',
            'name.unique'     => 'Site name already exists.',
            'address.string'  => 'Address must be a valid text value.',
            'address.max'     => 'Address is too long.',
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
