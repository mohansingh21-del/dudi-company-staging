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

    protected function prepareForValidation()
    {
        if ($this->filled('incident_date')) {
            try {
                $value = $this->input('incident_date');
                $date = null;

                if (\Carbon\Carbon::hasFormat($value, 'Y-m-d H:i:s')) {
                    $date = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);
                } elseif (\Carbon\Carbon::hasFormat($value, 'd/m/Y H:i:s')) {
                    $date = \Carbon\Carbon::createFromFormat('d/m/Y H:i:s', $value);
                } elseif (\Carbon\Carbon::hasFormat($value, 'd/m/Y')) {
                    $date = \Carbon\Carbon::createFromFormat('d/m/Y', $value)->setTimeFromTimeString(now()->format('H:i:s'));
                } elseif (\Carbon\Carbon::hasFormat($value, 'Y-m-d')) {
                    $date = \Carbon\Carbon::createFromFormat('Y-m-d', $value)->setTimeFromTimeString(now()->format('H:i:s'));
                }

                if ($date) {
                    $this->merge([
                        'incident_date' => $date->format('Y-m-d H:i:s')
                    ]);
                }
            } catch (\Throwable $e) {}
        }
    }

    public function rules()
    {

        return [

            'incident_date' => [
                'required',
                'bail',
                'date_format:Y-m-d H:i:s',
                function ($attribute, $value, $fail) {
                    try {
                        $date = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $value);

                        if ($date->isFuture()) {
                            $fail('Incident date cannot be a future date.');
                        }
                    } catch (\Throwable $e) {
                        $fail('The ' . $attribute . ' does not match the format Y-m-d H:i:s.');
                    }
                }
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


            // 'status' => [
            //     'required',
            //     Rule::in([
            //         'Under Review',
            //         'Investigation Closed'
            //     ])
            // ],


            'location_id' => [
                'required',
                'exists:sites,id'
            ],

            'equipment_id' => [
                'required',
                'exists:equipments,id'
            ],

            'equipment_name_id' => [

                'required',

                Rule::exists('equipment_names', 'id')
                    ->where(function ($query) {

                        $query->where(
                            'equipment_id',
                            request('equipment_id')
                        );
                    })
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

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $incidentDate = $this->input('incident_date');
            $shiftId = $this->input('shift_id');
            $locationId = $this->input('location_id');
            $equipmentNameId = $this->input('equipment_name_id');

            $shiftPlan = null;
            if ($incidentDate && $shiftId && $locationId) {
                try {
                    $parsedDate = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $incidentDate)->format('Y-m-d');
                    $shiftPlan = \App\Models\ShiftPlan::where('planning_date', $parsedDate)
                        ->where('shift_id', $shiftId)
                        ->where('site_id', $locationId)
                        ->first();
                } catch (\Throwable $e) {}
            }

            if ($shiftPlan) {
                $this->merge(['shift_plan_id' => $shiftPlan->id]);
            }

            if ($equipmentNameId) {
                if (!$shiftPlan) {
                    $validator->errors()->add('equipment_name_id', 'No active shift plan found for the selected date, shift, and site.');
                } else {
                    $allocated = \App\Models\ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlan->id)
                        ->where('equipment_name_id', $equipmentNameId)
                        ->exists();

                    if (!$allocated) {
                        $validator->errors()->add('equipment_name_id', 'The selected machine is not assigned to the shift plan for this date, shift, and site.');
                    }
                }
            }
        });
    }
}
