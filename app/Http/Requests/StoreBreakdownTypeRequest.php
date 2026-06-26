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
                'unique:breakdown_types,breakdown_type'
            ],

            'description' => [
                'nullable'
            ]
        ];
    }

    public function messages()
    {
        return [
            'breakdown_type.required' => 'Breakdown type is required.',

            'breakdown_type.unique' => 'Breakdown type already exists.'
        ];
    }
}
