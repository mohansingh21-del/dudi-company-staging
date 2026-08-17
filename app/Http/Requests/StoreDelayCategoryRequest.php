<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDelayCategoryRequest extends FormRequest
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
            'delay_category' => [
                'required',
                'unique:delay_categories,delay_category'
            ],
            'description' => [
                'nullable'
            ]
        ];
    }

    public function messages()
    {
        return [
            'delay_category.required' => 'Delay category is required.',
            'delay_category.unique' => 'Delay category already exists.'
        ];
    }
}
