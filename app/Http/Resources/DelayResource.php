<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DelayResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'delay_ref_no' => $this->delay_ref_no,
            'shift_plan_id' => $this->shift_plan_id,
            'shift_id' => $this->shift_id,
            'shift_date' => $this->shift_date ? $this->shift_date->format('Y-m-d') : null,
            'shift_name' => $this->shift_name,
            'delay_log_date' => $this->delay_log_date ? $this->delay_log_date->toDateTimeString() : null,
            'delay_category_id' => $this->delay_category_id,
            'delay_category_name' => optional($this->delayCategory)->delay_category,
            'delay_subcategory' => $this->delay_subcategory,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'duration_minutes' => $this->duration_minutes,
            'severity' => $this->severity,
            'linked_breakdown_id' => $this->linked_breakdown_id,
            'linked_breakdown_ticket_number' => optional($this->linkedBreakdown)->ticket_number,
            'equipment_id' => $this->equipment_id,
            'equipment_category' => optional($this->equipment)->name,
            'equipment_name_id' => $this->equipment_name_id,
            'equipment_name' => optional($this->equipmentName)->equipment_name,
            'average_production_rate_per_hour' => $this->average_production_rate_per_hour,
            'estimated_production_loss_bcm' => $this->estimated_production_loss_bcm,
            'description' => $this->description,
            'remarks' => $this->remarks,
            'created_by' => $this->created_by,
            'created_by_name' => optional(optional($this->creator)->employee)->name ?? (optional($this->creator)->email ?? null),
            'updated_by' => $this->updated_by,
            'updated_by_name' => optional(optional($this->updater)->employee)->name ?? (optional($this->updater)->email ?? null),
            'created_at' => $this->created_at ? $this->created_at->toDateTimeString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toDateTimeString() : null,
            'audit_logs' => $this->relationLoaded('auditLogs') ? $this->auditLogs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'changed_by_name' => optional(optional($log->changedBy)->employee)->name ?? (optional($log->changedBy)->email ?? null),
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'created_at' => $log->created_at ? $log->created_at->toDateTimeString() : null,
                ];
            }) : [],
        ];
    }
}
