<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use App\Models\ServiceRecord;

/**
 * The Detailed Service Report shown behind "View Full Details".
 *
 * Shaped for the report modal: the checklist is grouped the way the panel reads
 * it (consumables, then filters together), and every spare part says whether an
 * amount actually exists rather than reporting an unpriced inventory issue as
 * zero rupees.
 */
class ServiceRecordReportResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                     => $this->id,
            'ticket_number'          => $this->ticket_number,
            // The outside store's own job card, present only when this record
            // drew parts from one. Unrelated to the VECV dealer job cards.
            'job_card_number'        => $this->job_card_number,

            'service_type'           => $this->service_type,
            'service_type_label'     => $this->service_type === 'repair' ? 'Repair' : 'General Service',
            'service_date'           => $this->service_date ? $this->service_date->toDateString() : null,
            'status'                 => $this->status,

            'machine' => [
                'id'            => $this->machine_id,
                'name'          => optional($this->machine)->equipment_name,
                'category_name' => optional(optional($this->machine)->equipment)->name,
            ],
            'site' => [
                'id'   => $this->site_id,
                'name' => optional($this->site)->site_name,
            ],
            // One record draws store-sourced parts from one store only.
            'store' => $this->store_id ? [
                'id'   => $this->store_id,
                'name' => optional($this->store)->name,
            ] : null,

            'is_breakdown_service'   => (bool) $this->is_breakdown_service,
            'breakdown' => $this->breakdown ? [
                'id'            => $this->breakdown->id,
                'ticket_number' => $this->breakdown->ticket_number,
                'status'        => $this->breakdown->status,
            ] : null,

            'hours_odometer_reading' => $this->hours_odometer_reading !== null
                ? (float) $this->hours_odometer_reading
                : null,
            'km_run'                 => $this->km_run !== null ? (float) $this->km_run : null,
            'time_gap_months'        => $this->time_gap_months,

            'downtime' => [
                'start'   => $this->downtime_start ? $this->downtime_start->toDateTimeString() : null,
                'end'     => $this->downtime_end ? $this->downtime_end->toDateTimeString() : null,
                'minutes' => $this->downtime_minutes,
                'hours'   => $this->downtime_minutes !== null
                    ? round($this->downtime_minutes / 60, 2)
                    : null,
            ],

            'checklist'    => $this->checklist(),
            'spare_parts'  => $this->spareParts(),
            'attachments'  => $this->attachments(),

            // The edit form shows the saved images and has to know when the
            // upload control should stop accepting more, so the cap ships with
            // them rather than being hardcoded client-side.
            'attachment_limits' => [
                'max'             => ServiceRecord::MAX_ATTACHMENTS,
                'used'            => $this->attachments->count(),
                'remaining_slots' => max(0, ServiceRecord::MAX_ATTACHMENTS - $this->attachments->count()),
            ],

            'totals' => [
                'base_service_amount'      => (float) $this->base_service_amount,
                'checklist_amount_total'   => (float) $this->checklist_amount_total,
                'spare_parts_amount_total' => (float) $this->spare_parts_amount_total,
                'total_amount'             => (float) $this->total_amount,
            ],

            'performed_by'     => $this->performed_by,
            'remarks'          => $this->remarks,
            'created_by_email' => optional($this->creator)->email,
            'updated_by_email' => optional($this->updater)->email,
            'created_at'       => optional($this->created_at)->toDateTimeString(),
        ];
    }

    /**
     * Replacements & Consumables panel.
     *
     * The two filter flags are reported together because the panel renders them
     * as a single "Filters" column listing whichever were changed.
     *
     * @return array
     */
    protected function checklist()
    {
        $detail = $this->checklistDetail;

        $item = function ($field, $amountField) use ($detail) {
            return [
                'done'   => $detail ? (bool) $detail->{$field} : false,
                'amount' => $detail ? (float) $detail->{$amountField} : 0.00,
            ];
        };

        $filtersChanged = [];

        if ($detail && $detail->fuel_filter_change) {
            $filtersChanged[] = 'Fuel Filter';
        }
        if ($detail && $detail->oil_filter_change) {
            $filtersChanged[] = 'Oil Filter';
        }

        return [
            'oil_change'    => $item('oil_change', 'oil_change_amount'),
            'hydraulic_oil' => $item('hydraulic_oil', 'hydraulic_oil_amount'),
            'gear_oil'      => $item('gear_oil', 'gear_oil_amount'),
            'filters'       => [
                'fuel_filter' => $item('fuel_filter_change', 'fuel_filter_change_amount'),
                'oil_filter'  => $item('oil_filter_change', 'oil_filter_change_amount'),
                'changed'     => $filtersChanged,
            ],
        ];
    }

    /**
     * Spare Parts Used table.
     *
     * @return array
     */
    protected function spareParts()
    {
        return $this->spareParts->map(function ($part) {
            $inventory = $part->inventory;
            $storeName = optional(optional($inventory)->store)->name;

            // Rows written before the two inventories were merged, and the
            // older free-text vendor rows, point at no stock row — the only
            // name they ever carried is the free-text vendor_name.
            $sourceLabel = $storeName ?: ($part->vendor_name ?: 'Other Vendors');

            return [
                'id'           => $part->id,
                'source_label' => $sourceLabel,
                'inventory_id' => $part->inventory_id,
                'product_id'   => optional($inventory)->product_id,
                'store_id'     => optional($inventory)->store_id,
                'store_name'   => $storeName,
                'part_name'    => $part->part_name,
                'quantity'     => (float) $part->quantity,
                'unit_price'   => (float) $part->unit_price,
                'amount'       => (float) $part->amount,
                // A part the caller did not price has no billable amount to
                // show — that is not the same as costing zero rupees, and the
                // report must not imply it is.
                'is_priced'    => (float) $part->unit_price > 0.00,
            ];
        })->values()->toArray();
    }

    /**
     * @return array
     */
    protected function attachments()
    {
        return $this->attachments->map(function ($file) {
            return [
                'id'        => $file->id,
                'file_name' => $file->file_name,
                'file_type' => $file->file_type,
                'file_size' => (int) $file->file_size,
                'url'       => Storage::disk('public')->url($file->file_path),
            ];
        })->values()->toArray();
    }
}
