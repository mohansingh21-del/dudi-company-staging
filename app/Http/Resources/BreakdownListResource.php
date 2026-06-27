<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BreakdownListResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                  => $this->id,
            'ticket_number'       => $this->ticket_number,
            'shift_id'            => $this->shift_id,
            'shift_name'          => optional($this->shift)->shift_name,
            'equipment_id'        => $this->equipment_id,
            'equipment_category'  => optional($this->equipment)->name,
            'equipment_name_id'       => $this->equipment_name_id,
            'equipment_name'          => optional($this->equipmentName)->equipment_name,
            'equipment_allocation_id' => $this->equipment_allocation_id,
            'breakdown_date_time'     => $this->breakdown_date_time ? $this->breakdown_date_time->toDateTimeString() : null,
            'severity'                => $this->severity,
            'status'                  => $this->status,
            'downtime_start'      => $this->downtime_start ? $this->downtime_start->toDateTimeString() : null,
            'downtime_end'        => $this->downtime_end ? $this->downtime_end->toDateTimeString() : null,
            'downtime_minutes'    => $this->downtime_minutes,
            'reported_by_name'    => optional($this->reporter)->name,
            'breakdown_type_id'   => $this->breakdown_type_id,
            'breakdown_type'      => optional($this->breakdownType)->breakdown_type,
            'brek_down_type'      => optional($this->breakdownType)->breakdown_type,
            'resolved_at'         => $this->resolved_at ? $this->resolved_at->toDateTimeString() : null,
        ];
    }
}
