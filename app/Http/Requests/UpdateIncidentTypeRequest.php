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

                Rule::unique(
                    'incident_types',
                    'incident_type'
                )->ignore($id)

            ],

            'description' => 'nullable'

        ];
    }
}
