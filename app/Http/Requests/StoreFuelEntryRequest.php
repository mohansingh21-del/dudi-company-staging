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
            'equipment_allocation_id' => 'required|integer|exists:shift_equipment_allocations,id',
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
        });
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status'  => 422,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422)
        );
    }
}
