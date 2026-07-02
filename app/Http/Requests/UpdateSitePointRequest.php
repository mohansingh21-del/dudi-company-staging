<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSitePointRequest extends FormRequest
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
        $id = $this->route('id');

        return [
            'site_id' => [
                'sometimes',
                'required',
                'exists:sites,id',
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
            ],
            'type' => [
                'sometimes',
                'required',
                'in:loading,dumping',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }


    /**
     * Custom validation messages.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'site_id.exists'  => 'Selected site does not exist.',
            'name.required'   => 'Point name is required.',
            'type.in'         => 'Point type must be loading or dumping.',
        ];
    }
}
