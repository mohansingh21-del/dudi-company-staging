<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBreakdownTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'breakdown_type' => [
                'required',
                'string',
                'max:255',
                'unique:breakdown_types,breakdown_type'
            ],

            'description' => [
                'nullable',
                'string',
                'max:5000'
            ]
        ];
    }

    public function messages()
    {
        return [
            'breakdown_type.required' => 'Breakdown type is required.',

            'breakdown_type.unique' => 'Breakdown type already exists.',

            'breakdown_type.max' => 'Breakdown type cannot be longer than 255 characters.',

            'description.max' => 'Description cannot be longer than 5000 characters.'
        ];
    }
}
