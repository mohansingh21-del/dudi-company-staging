<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'incident_date' => [
                'required',
                'date_format:d/m/Y',
                function ($attribute, $value, $fail) {

                    $date = \Carbon\Carbon::createFromFormat('d/m/Y', $value);

                    if ($date->isFuture()) {
                        $fail('Incident date cannot be a future date.');
                    }
                }
            ],
            'shift_id' => [
                'required',
                'exists:shifts,id'
            ],

            'incident_type_id' => [

                'required',

                Rule::exists('incident_types', 'id')
                    ->where(function ($query) {

                        $query->where('is_active', 1);
                    })

            ],

            'severity' => [

                'required',

                Rule::in([
                    'LOW',
                    'MEDIUM',
                    'HIGH',
                    'CRITICAL'
                ])

            ],



            'location_id' => [
                'required',
                'exists:sites,id'
            ],

            'person_involved_id' => [
                'nullable',
                'exists:employees,id'
            ],

            'incident_description' => [
                'required',
                'string'
            ],

            'action_taken' => [
                'required',
                'string'
            ],

            'preventive_measures' => [
                'nullable',
                'string'
            ],

            'media' => [
                'nullable',
                'array'
            ],

            'media.*' => [

                'file',

                'mimes:jpg,jpeg,png,pdf',

                'max:10240' // 10 MB

            ]

        ];
    }

    public function messages(): array
    {
        return [

            'incident_date.required' =>
            'Incident date is required.',

            'incident_date.before_or_equal' =>
            'Incident date cannot be a future date.',

            'shift_id.required' =>
            'Shift is required.',

            'shift_id.exists' =>
            'Selected shift does not exist.',

            'incident_type_id.required' =>
            'Incident type is required.',

            'incident_type_id.exists' =>
            'Selected incident type is invalid or inactive.',

            'severity.required' =>
            'Severity is required.',

            'severity.in' =>
            'Invalid severity selected.',

            'status.required' =>
            'Status is required.',

            'status.in' =>
            'Invalid status selected.',

            'location_id.required' =>
            'Location is required.',

            'location_id.exists' =>
            'Selected location does not exist.',

            'person_involved_id.exists' =>
            'Selected employee does not exist.',

            'incident_description.required' =>
            'Incident description is required.',

            'action_taken.required' =>
            'Action taken is required.',

            'media.*.mimes' =>
            'Media must be jpg, jpeg, png or pdf.',

            'media.*.max' =>
            'Each file must not exceed 10 MB.'

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
