<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }


    public function rules()
    {

        return [

            'incident_date' => [
                'required',
                'date',
                'before_or_equal:today'
            ],


            'shift_id' => [
                'required',
                'exists:shifts,id'
            ],


            'incident_type_id' => [
                'required',
                'exists:incident_types,id',
                Rule::exists('incident_types', 'id')
                    ->where('is_active', 1)
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


            'status' => [
                'required',
                Rule::in([
                    'Reported',
                    'Under Review',
                    'Action Required',
                    'Investigation Closed'
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


            'incident_description' => 'required',


            'action_taken' => 'required',


            'preventive_measures' => 'nullable',



            'media.*' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240'
            ]


        ];
    }
}
