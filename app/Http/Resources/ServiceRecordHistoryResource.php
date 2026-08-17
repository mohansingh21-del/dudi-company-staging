<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in the equipment master's Service History Log timeline.
 *
 * Carries only what the timeline card renders. The full record — checklist
 * amounts, spare part rows, attachments, audit trail — stays behind the
 * "View Full Details" link on GET /service-records/{id}.
 */
class ServiceRecordHistoryResource extends JsonResource
{
    /**
     * Checklist flags mapped to the labels shown as chips on the card.
     *
     * @var array
     */
    protected static $checklistLabels = [
        'oil_change'         => 'Engine Oil',
        'hydraulic_oil'      => 'Hydraulic Oil',
        'gear_oil'           => 'Gear Oil',
        'fuel_filter_change' => 'Fuel Filter',
        'oil_filter_change'  => 'Oil Filter',
    ];

    public function toArray($request)
    {
        return [
            'id'                     => $this->id,
            'ticket_number'          => $this->ticket_number,
            'service_date'           => $this->service_date ? $this->service_date->toDateString() : null,
            'service_type'           => $this->service_type,
            'status'                 => $this->status,
            'hours_odometer_reading' => $this->hours_odometer_reading !== null
                ? (float) $this->hours_odometer_reading
                : null,
            'km_run'                 => $this->km_run !== null ? (float) $this->km_run : null,
            'downtime_minutes'       => $this->downtime_minutes,
            'total_amount'           => (float) $this->total_amount,
            'spare_parts_count'      => (int) $this->spare_parts_count,
            'attachments_count'      => (int) $this->attachments_count,
            'checklist_items'        => $this->checklistItems(),
            'is_breakdown_service'   => (bool) $this->is_breakdown_service,
            'breakdown_ticket'       => optional($this->breakdown)->ticket_number,
            'performed_by'           => $this->performed_by,
        ];
    }

    /**
     * Labels for the checklist work actually carried out on this service.
     *
     * @return array
     */
    protected function checklistItems()
    {
        $checklist = $this->checklistDetail;

        if (!$checklist) {
            return [];
        }

        $items = [];

        foreach (self::$checklistLabels as $field => $label) {
            if ($checklist->{$field}) {
                $items[] = $label;
            }
        }

        return $items;
    }
}
