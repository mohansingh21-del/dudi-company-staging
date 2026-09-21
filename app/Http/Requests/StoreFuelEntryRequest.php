<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreFuelEntryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'shift_plan_id' => 'required|integer|exists:shift_plans,id',
            'fuel_log_date' => 'nullable|date',
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id', new \App\Rules\ActiveShift()],
            'equipment_allocation_id' => 'nullable|integer|exists:shift_equipment_allocations,id',
            'equipment_id' => 'nullable|integer|exists:equipments,id',
            'equipment_name_id' => 'nullable|integer|exists:equipment_names,id',
            'operator_id' => 'nullable|integer|exists:users,id',
            'fuel_source' => 'nullable|string|in:fuel_tanker,fuel_station,mobile_refueling_unit',
            'opening_fuel' => 'required|numeric|min:0',
            'fuel_issued' => 'required|numeric|gt:0',
            'closing_fuel' => 'nullable|numeric|min:0',
            'hours_meter_reading' => 'nullable|numeric|min:0',
            'kilometer_reading' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string|max:500',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $opening = $this->input('opening_fuel');
            $issued = $this->input('fuel_issued');
            $closing = $this->input('closing_fuel');

            if ($opening !== null && $issued !== null && $closing !== null) {
                if ($closing > ($opening + $issued)) {
                    $validator->errors()->add('closing_fuel', 'Closing fuel cannot be greater than opening fuel + fuel issued.');
                }
            }

            $shiftPlanId = $this->input('shift_plan_id');
            $shiftId = $this->input('shift_id');

            if ($shiftPlanId && $shiftId) {
                $shiftPlan = \App\Models\ShiftPlan::find($shiftPlanId);
                if ($shiftPlan && $shiftPlan->shift_id != $shiftId) {
                    $validator->errors()->add('shift_id', 'The selected shift does not match the shift plan.');
                }
            }
        });
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
