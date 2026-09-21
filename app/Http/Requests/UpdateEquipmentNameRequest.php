<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;


class UpdateEquipmentNameRequest extends FormRequest
{


    public function authorize(): bool
    {
        return true;
    }



    public function rules(): array
    {

        $equipmentNameId = $this->route('equipment_name');


        return [

            'equipment_id' =>
            'required|exists:equipments,id',


            'equipment_name' => [

                'required',

                'string',

                'max:255',

                Rule::unique(
                    'equipment_names',
                    'equipment_name'
                )->ignore($equipmentNameId)

            ]

        ];
    }



    public function messages(): array
    {

        return [

            'equipment_name.required' =>
            'Equipment name is required.',


            'equipment_name.unique' =>
            'Equipment name already exists.',

            'equipment_name.max' =>
            'Equipment name cannot be longer than 255 characters.'

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
