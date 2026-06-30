<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDelayCategoryRequest extends FormRequest
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
        $id = $this->route('delay_category') ?? $this->route('id');

        return [
            'delay_category' => [
                'required',
                Rule::unique('delay_categories', 'delay_category')->ignore($id)
            ],
            'description' => 'nullable'
        ];
    }
}
