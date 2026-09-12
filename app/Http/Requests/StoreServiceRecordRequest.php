<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use App\Http\Requests\Traits\NormalizesServiceRecordInput;
use App\Http\Requests\Traits\ValidatesSpareParts;
use App\Models\ServiceRecord;

class StoreServiceRecordRequest extends FormRequest
{
    use NormalizesServiceRecordInput;
    use ValidatesSpareParts;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $breakdownTable = Schema::hasTable('breakdowns') ? 'breakdowns' : 'breakdown_tickets';
        $machineTable = Schema::hasTable('machines') ? 'machines' : 'equipment_names';

        return [
            'is_breakdown_service'                 => 'required|boolean',
            'breakdown_id'                         => 'required_if:is_breakdown_service,true,1|nullable|integer|exists:' . $breakdownTable . ',id',
            'machine_id'                           => 'required_unless:is_breakdown_service,true,1|nullable|integer|exists:' . $machineTable . ',id',
            'site_id'                              => 'nullable|integer|exists:sites,id',
            // Every service carries a job card. The store is required once the
            // record has parts, and every part must come from it — see
            // ValidatesSpareParts.
            'store_id'                             => 'nullable|integer|exists:stores,id',
            'job_card_number'                      => 'required|string|max:64',
            'service_date'                         => 'required|date',
            'hours_odometer_reading'               => 'nullable|numeric|min:0',
            'km_run'                               => 'nullable|numeric|min:0',
            'time_gap_months'                      => 'nullable|integer|min:0',

            // Times only ("08:00" or "08:00:00") — the date comes from service_date.
            // Filling both ends completes the service, and closes the linked
            // breakdown ticket when this is a breakdown service.
            'downtime_start'                       => 'required_with:downtime_end|nullable|date_format:H:i:s,H:i',
            'downtime_end'                         => 'nullable|date_format:H:i:s,H:i|different:downtime_start',

            'base_service_amount'                  => 'nullable|numeric|min:0',
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
            // The stock row the part came out of. It names both the product
            // and the store, so nothing else has to be sent to identify it.
            'spare_parts.*.inventory_id'           => 'required_with:spare_parts|integer|exists:inventories,id',
            // The name is resolved from the product catalog, so a
            // caller-supplied one is only ever a fallback.
            'spare_parts.*.part_name'              => 'nullable|string|max:255',
            'spare_parts.*.vendor_name'            => 'nullable|string|max:255',
            'spare_parts.*.quantity'               => 'required_with:spare_parts|numeric|min:0.01',
            // The caller prices the whole line, not each unit: a quantity of 4
            // comes with one amount covering all 4. unit_price is derived from
            // it server-side. A part left unpriced costs nothing.
            'spare_parts.*.amount'                 => 'nullable|numeric|min:0',

            // A new record starts with nothing on file, so the total cap is a
            // plain per-request cap here. Updates have to count what is already
            // stored — see UpdateServiceRecordRequest.
            'attachments'                          => 'nullable|array|max:' . ServiceRecord::MAX_ATTACHMENTS,
            'attachments.*'                        => 'file|mimes:jpg,jpeg,png,pdf|max:5120',
            'remarks'                              => 'nullable|string',
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'attachments.max' => 'A service record can hold at most '
                . ServiceRecord::MAX_ATTACHMENTS . ' images.',
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
