<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;


class UpdateIncidentTypeRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }


    public function rules()
    {

        $id = $this->route('id');


        return [

            'incident_type' => [

                'required',

                'string',

                'max:255',

                Rule::unique(
                    'incident_types',
                    'incident_type'
                )->ignore($id)

            ],

            'description' => 'nullable|string|max:5000'

        ];
    }

    public function messages()
    {
        return [
            'incident_type.required' => 'Incident type is required.',
            'incident_type.unique' => 'Incident type already exists.',
            'incident_type.max' => 'Incident type cannot be longer than 255 characters.',
            'description.max' => 'Description cannot be longer than 5000 characters.',
        ];
    }
}
