<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EquipmentResource extends JsonResource
{
    public function toArray($request)
    {
        return [

            'id' => $this->id,

            'equipment_id' => $this->equipment_id,

            'equipment_category' => $this->equipment?->name,

            'equipment_name' => $this->equipment_name,

            'status' => $this->is_active

        ];
    }
}
