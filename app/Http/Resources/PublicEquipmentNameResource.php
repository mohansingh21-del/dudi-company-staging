<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\BreakdownTicket;
use App\Models\ShiftEquipmentAllocation;

class PublicEquipmentNameResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $status = 'available';
        $shortReason = null;

        if (!$this->is_active) {
            $status = 'unavailable';
            $shortReason = 'Inactive';
        } elseif (BreakdownTicket::where('equipment_name_id', $this->id)->where('status', '!=', 'closed')->exists()) {
            $status = 'unavailable';
            $shortReason = 'In maintenance';
        } elseif ($allocation = ShiftEquipmentAllocation::where('equipment_name_id', $this->id)->whereHas('shiftPlan', function ($q) {
            $q->notClosed();
        })->first()) {
            $status = 'unavailable';
            $shiftName = optional(optional($allocation->shiftPlan)->shift)->shift_name;
            $shortReason = $shiftName ? "Already allocated to {$shiftName}" : "Already allocated";
        }

        return [
            'id' => $this->id,
            'equipment_category_id' => $this->equipment ? $this->equipment->id : null,
            'equipment_category_name' => $this->equipment ? $this->equipment->name : null,
            'equipment_id' => $this->equipment_id,
            'equipment_name' => $this->equipment_name,
            'status' => $status,
            'short_reason' => $shortReason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
