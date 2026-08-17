<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLogResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => optional($this->product)->name,
            'user_id' => $this->user_id,
            'user_name' => optional($this->user)->name ?? 'System',
            'done_by' => $this->user
    ? ($this->user->name ?: optional($this->user->roles->first())->name)
    : 'System',
            'type' => $this->type,
            'action' => $this->action,
            'quantity' => (float) $this->quantity,
            'remarks' => $this->remarks,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
