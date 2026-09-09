<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use App\Http\Requests\Traits\NormalizesServiceRecordInput;

class UpdateServiceRecordRequest extends FormRequest
{
    use NormalizesServiceRecordInput;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $breakdownTable = Schema::hasTable('breakdowns') ? 'breakdowns' : 'breakdown_tickets';
        $machineTable = Schema::hasTable('machines') ? 'machines' : 'equipment_names';
        $productTable = Schema::hasTable('inventory_products') ? 'inventory_products' : 'products';

        return [
            'is_breakdown_service'                 => 'sometimes|boolean',
            'breakdown_id'                         => 'required_if:is_breakdown_service,true,1|nullable|integer|exists:' . $breakdownTable . ',id',
            'machine_id'                           => 'nullable|integer|exists:' . $machineTable . ',id',
            'service_date'                         => 'sometimes|date',
            'hours_odometer_reading'               => 'nullable|numeric|min:0',
            'km_run'                               => 'nullable|numeric|min:0',
            'time_gap_months'                      => 'nullable|integer|min:0',

            // Times only ("08:00" or "08:00:00") — the date comes from service_date.
            // Filling both ends completes the service, and closes the linked
            // breakdown ticket when this is a breakdown service.
            'downtime_start'                       => 'required_with:downtime_end|nullable|date_format:H:i:s,H:i',
            'downtime_end'                         => 'nullable|date_format:H:i:s,H:i|different:downtime_start',

            'base_service_amount'                  => 'nullable|numeric|min:0',
            'status'                               => 'sometimes|string|in:pending,in_progress,completed,cancelled',
            'performed_by'                         => 'nullable|string|max:255',

            'checklist'                            => 'nullable|array',
            'checklist.oil_change'                 => 'nullable|boolean',
            'checklist.oil_change_amount'          => 'nullable|numeric|min:0',
            'checklist.hydraulic_oil'              => 'nullable|boolean',
            'checklist.hydraulic_oil_amount'       => 'nullable|numeric|min:0',
            'checklist.gear_oil'                   => 'nullable|boolean',
            'checklist.gear_oil_amount'            => 'nullable|numeric|min:0',
            'checklist.fuel_filter_change'         => 'nullable|boolean',
            'checklist.fuel_filter_change_amount'  => 'nullable|numeric|min:0',
            'checklist.oil_filter_change'          => 'nullable|boolean',
            'checklist.oil_filter_change_amount' => 'nullable|numeric|min:0',

            'spare_parts_changed'                  => 'nullable|boolean',
            'spare_parts'                          => 'required_if:spare_parts_changed,true,1|nullable|array',
            'spare_parts.*.source'                 => 'required_with:spare_parts|string|in:inventory,vendor',
            'spare_parts.*.inventory_product_id'   => 'required_if:spare_parts.*.source,inventory|nullable|integer|exists:' . $productTable . ',id',
            'spare_parts.*.part_name'              => 'required_if:spare_parts.*.source,vendor|nullable|string|max:255',
            'spare_parts.*.vendor_name'            => 'nullable|string|max:255',
            'spare_parts.*.quantity'               => 'required_with:spare_parts|numeric|min:0.01',
            'spare_parts.*.amount'                 => 'required_if:spare_parts.*.source,vendor|nullable|numeric|min:0',

            'attachments'                          => 'nullable|array',
            'attachments.*'                        => 'file|mimes:jpg,jpeg,png,pdf|max:5120',
            'remarks'                              => 'nullable|string',
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
