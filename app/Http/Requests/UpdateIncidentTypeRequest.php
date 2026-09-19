<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;


class UpdateIncidentTypeRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }


    public function rules()
    {

        // apiResource binds the route parameter as {incident_type}
        $id = $this->route('incident_type') ?? $this->route('id');


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

            'description' => 'nullable|string|max:1000'

        ];
    }


    public function messages()
    {
        return [

            'incident_type.required'
            => 'Incident type is required.',


            'incident_type.string'
            => 'Incident type must be a valid text value.',


            'incident_type.max'
            => 'Incident type may not be greater than 255 characters.',


            'incident_type.unique'
            => 'Incident type already exists.',


            'description.string'
            => 'Description must be a valid text value.',


            'description.max'
            => 'Description may not be greater than 1000 characters.'

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
