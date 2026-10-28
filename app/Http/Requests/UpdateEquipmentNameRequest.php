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

            ],


            'equipment_code' => [

                'nullable',

                'string',

                'max:50',

                Rule::unique(
                    'equipment_names',
                    'equipment_code'
                )->ignore($equipmentNameId)

            ],


            'chassis_number' => [

                'nullable',

                'string',

                'max:32',

                Rule::unique(
                    'equipment_names',
                    'chassis_number'
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
            'Equipment name cannot be longer than 255 characters.',

            'equipment_code.unique' =>
            'Equipment code already exists.',

            'equipment_code.max' =>
            'Equipment code cannot be longer than 50 characters.',

            'chassis_number.unique' =>
            'Chassis number is already assigned to another machine.',

            'chassis_number.max' =>
            'Chassis number cannot be longer than 32 characters.'

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
