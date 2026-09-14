<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use App\Http\Requests\Traits\NormalizesServiceRecordInput;
use App\Http\Requests\Traits\ValidatesSpareParts;
use App\Models\ServiceRecord;

class UpdateServiceRecordRequest extends FormRequest
{
    use NormalizesServiceRecordInput;

    // This request needs an attachment check of its own on top of the shared
    // one, and a withValidator() declared here would shadow the trait's
    // silently. Aliasing keeps both running — see withValidator() below.
    use ValidatesSpareParts {
        withValidator as validateSparePartsStore;
    }

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $breakdownTable = Schema::hasTable('breakdowns') ? 'breakdowns' : 'breakdown_tickets';
        $machineTable = Schema::hasTable('machines') ? 'machines' : 'equipment_names';

        return [
            'is_breakdown_service'                 => 'sometimes|boolean',
            'breakdown_id'                         => 'required_if:is_breakdown_service,true,1|nullable|integer|exists:' . $breakdownTable . ',id',
            'machine_id'                           => 'nullable|integer|exists:' . $machineTable . ',id',
            // Every service carries a job card. The store is required once the
            // record has parts, and every part must come from it — see
            // ValidatesSpareParts.
            'store_id'                             => 'nullable|integer|exists:stores,id',
            'job_card_number'                      => 'sometimes|required|string|max:64',
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
            // The product the line is for. inventory_id is never read from the
            // payload — the service resolves it from this and the record's store.
            'spare_parts.*.store_product_id'       => 'nullable|integer',
            'spare_parts.*.product_id'             => 'nullable|integer',
            // The name is resolved from the product catalog, so a
            // caller-supplied one is only ever a fallback.
            'spare_parts.*.part_name'              => 'nullable|string|max:255',
            'spare_parts.*.vendor_name'            => 'nullable|string|max:255',
            'spare_parts.*.quantity'               => 'required_with:spare_parts|numeric|min:0.01',
            // The per-unit price, not the line total: a quantity of 4 at an
            // amount of 100 is a 400 line, worked out server-side. A part left
            // unpriced costs nothing.
            'spare_parts.*.amount'                 => 'nullable|numeric|min:0',

            // Uploads add to what the record already holds, they don't replace
            // it, so the cap can't be a flat max here — it's checked against the
            // stored count in withValidator().
            'attachments'                          => 'nullable|array',
            'attachments.*'                        => 'file|mimes:jpg,jpeg,png,pdf|max:5120',
            'remarks'                              => 'nullable|string',
        ];
    }

    /**
     * Run the shared spare-parts store check, then reject an upload that would
     * push the record past its attachment cap.
     *
     * The client is told how many slots are actually free so it can prompt the
     * user to remove images first — DELETE /service-records/{id}/attachments/{id}.
     *
     * @param  Validator  $validator
     * @return void
     */
    public function withValidator(Validator $validator)
    {
        $this->validateSparePartsStore($validator);

        $validator->after(function (Validator $validator) {
            $incoming = $this->file('attachments');

            if (!is_array($incoming) || empty($incoming)) {
                return;
            }

            $record = $this->route('service_record');

            if (!$record instanceof ServiceRecord) {
                return;
            }

            $existing = $record->attachments()->count();
            $remaining = ServiceRecord::MAX_ATTACHMENTS - $existing;

            if (count($incoming) <= $remaining) {
                return;
            }

            if ($remaining <= 0) {
                $message = 'This service record already has the maximum of '
                    . ServiceRecord::MAX_ATTACHMENTS . ' images. Remove one before uploading another.';
            } elseif ($existing === 0) {
                $message = 'A service record can hold at most '
                    . ServiceRecord::MAX_ATTACHMENTS . ' images.';
            } else {
                $message = 'This service record already has ' . $existing . ' of '
                    . ServiceRecord::MAX_ATTACHMENTS . ' images. You can upload ' . $remaining
                    . ' more — remove an existing image to add others.';
            }

            $validator->errors()->add('attachments', $message);
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
