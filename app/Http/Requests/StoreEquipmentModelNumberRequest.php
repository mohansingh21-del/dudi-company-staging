<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreEquipmentModelNumberRequest extends FormRequest
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

            // Unique within the category: two categories may share a model code.
            'model_number' => [

                'required',

                'string',

                'max:100',

                Rule::unique('equipment_model_numbers', 'model_number')
                    ->where('equipment_id', $this->input('equipment_id'))

            ],

            'manufacturer' =>
            'nullable|string|max:255'

        ];
    }


    public function messages(): array
    {

        return [

            'equipment_id.required' =>
            'Equipment category is required.',

            'equipment_id.exists' =>
            'Invalid equipment category.',

            'model_number.required' =>
            'Model number is required.',

            'model_number.unique' =>
            'Model number already exists for this category.',

            'model_number.max' =>
            'Model number cannot be longer than 100 characters.',

            'manufacturer.max' =>
            'Manufacturer cannot be longer than 255 characters.'

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
