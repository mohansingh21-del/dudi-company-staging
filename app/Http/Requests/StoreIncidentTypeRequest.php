<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentTypeRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }


    public function rules()
    {
        return [

            'incident_type' => [
                'required',
                'unique:incident_types,incident_type'
            ],

            'description' => [
                'nullable'
            ]

        ];
    }


    public function messages()
    {
        return [

            'incident_type.required'
            => 'Incident type is required.',


            'incident_type.unique'
            => 'Incident type already exists.'

        ];
    }
}
