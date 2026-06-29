<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\FuelEntry;

class UpdateFuelEntryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'operator_id' => 'nullable|integer|exists:users,id',
            'fuel_source' => 'nullable|string|in:fuel_tanker,fuel_station,mobile_refueling_unit',
            'opening_fuel' => 'nullable|numeric|min:0',
            'fuel_issued' => 'nullable|numeric|gt:0',
            'closing_fuel' => 'nullable|numeric|min:0',
            'hours_meter_reading' => 'nullable|numeric|min:0',
            'kilometer_reading' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string|max:500',
            'work_done_bcm' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:active,voided',
            'fuel_ref_no' => 'nullable',
            'shift_plan_id' => 'nullable',
            'fuel_log_date' => 'nullable',
            'shift_id' => 'nullable',
            'equipment_allocation_id' => 'nullable',
            'equipment_id' => 'nullable',
            'equipment_name_id' => 'nullable',
            'fuel_consumption' => 'nullable',
            'fuel_per_bcm' => 'nullable',
            'created_by' => 'nullable',
            'created_at' => 'nullable',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $fuelEntryId = $this->route('id');
            $entry = FuelEntry::find($fuelEntryId);

            $opening = $this->has('opening_fuel') ? $this->input('opening_fuel') : ($entry ? $entry->opening_fuel : null);
            $issued = $this->has('fuel_issued') ? $this->input('fuel_issued') : ($entry ? $entry->fuel_issued : null);
            $closing = $this->has('closing_fuel') ? $this->input('closing_fuel') : ($entry ? $entry->closing_fuel : null);

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
