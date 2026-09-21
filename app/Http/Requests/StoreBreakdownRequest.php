<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreBreakdownRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'shift_id' => ['required', 'integer', 'exists:shifts,id', new \App\Rules\ActiveShift()],
            'breakdown_date_time' => 'required|date_format:Y-m-d H:i:s',
            'reported_by' => 'required|integer|exists:employees,id',
            'equipment_id' => 'required|integer|exists:equipments,id',
            'equipment_name_id' => 'required|integer|exists:equipment_names,id',
            'equipment_allocation_id' => 'nullable|integer|exists:shift_equipment_allocations,id',
            'breakdown_type_id' => 'required|integer|exists:breakdown_types,id',
            'severity' => 'required|string|in:LOW,MEDIUM,HIGH,CRITICAL',
            'description' => 'required|string|max:1000',
            // Downtime is captured on the service record now, not here. A ticket is
            // always raised open and closes only when a service record records its
            // downtime window.
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
