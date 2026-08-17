<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBreakdownTypeRequest extends FormRequest
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
        $id = $this->route('breakdown_type') ?? $this->route('id');

        return [

            'breakdown_type' => [

                'required',

                Rule::unique(
                    'breakdown_types',
                    'breakdown_type'
                )->ignore($id)

            ],

            'description' => 'nullable'

        ];
    }
}
