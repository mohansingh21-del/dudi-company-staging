<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
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

                'string',

                'max:255',

                Rule::unique(
                    'breakdown_types',
                    'breakdown_type'
                )->ignore($id)

            ],

            'description' => 'nullable|string|max:1000'

        ];
    }

    public function messages()
    {
        return [
            'breakdown_type.required' => 'Breakdown type is required.',

            'breakdown_type.string' => 'Breakdown type must be a valid text value.',

            'breakdown_type.max' => 'Breakdown type may not be greater than 255 characters.',

            'breakdown_type.unique' => 'Breakdown type already exists.',

            'description.string' => 'Description must be a valid text value.',

            'description.max' => 'Description may not be greater than 1000 characters.'
        ];
    }

    protected function failedValidation(Validator $validator)
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
