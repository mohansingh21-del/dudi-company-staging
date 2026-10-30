<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreEquipmentNameRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {

        return [

            'equipment_id' =>
            'required|exists:equipments,id',

            // Must be an active model of the category the machine is filed under.
            'equipment_model_number_id' => [

                'required',

                'integer',

                Rule::exists('equipment_model_numbers', 'id')
                    ->where('equipment_id', $this->input('equipment_id'))
                    ->where('is_active', 1)

            ],


            'equipment_name' =>
            'required|string|max:255|unique:equipment_names,equipment_name',

            'chassis_number' =>
            'nullable|string|max:32|unique:equipment_names,chassis_number'

        ];
    }


    public function messages(): array
    {

        return [

            'equipment_id.required' =>
            'Equipment category is required.',

            'equipment_id.exists' =>
            'Invalid equipment category.',

            'equipment_model_number_id.required' =>
            'Model number is required.',

            'equipment_model_number_id.exists' =>
            'Selected model number is inactive or does not belong to this equipment category.',

            'equipment_name.required' =>
            'Equipment name is required.',

            'equipment_name.unique' =>
            'Equipment name already exists.',

            'equipment_name.max' =>
            'Equipment name cannot be longer than 255 characters.',

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
