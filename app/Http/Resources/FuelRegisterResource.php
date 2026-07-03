<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FuelRegisterResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'fuel_ref_no' => $this->fuel_ref_no,
            'fuel_log_date' => $this->fuel_log_date ? $this->fuel_log_date->toDateTimeString() : null,
            'fuel_source' => $this->fuel_source,
            'opening_fuel' => $this->opening_fuel,
            'fuel_issued' => $this->fuel_issued,
            'closing_fuel' => $this->closing_fuel,
            'fuel_consumption' => $this->fuel_consumption,
            'hours_meter_reading' => $this->hours_meter_reading,
            'kilometer_reading' => $this->kilometer_reading,
            'fuel_per_hour' => $this->fuel_per_hour,
            'fuel_per_km' => $this->fuel_per_km,
            'work_done_bcm' => $this->work_done_bcm !== null ? (float) $this->work_done_bcm : null,
            'fuel_per_bcm' => $this->fuel_per_bcm !== null ? (float) $this->fuel_per_bcm : null,
            'status' => $this->status,
            'remarks' => $this->remarks,
            'equipment_allocation_id' => $this->equipment_allocation_id,
            'equipment_id' => $this->equipment_id,
            'equipment_name_id' => $this->equipment_name_id,
            'machine_name' => optional($this->equipmentName)->equipment_name,
            'category_name' => optional($this->equipment)->name ?? optional(optional($this->equipmentName)->equipment)->name,
            'shift_name' => optional($this->shift)->shift_name,
        ];
    }
}
