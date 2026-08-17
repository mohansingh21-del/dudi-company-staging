<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => optional($this->product)->name,
            'category_name' => optional(optional(optional($this->product)->subCategory)->category)->name,      
            'sub_category_name' => optional(optional($this->product)->subCategory)->name,
            'total_stock' => (float) $this->quantity,
            'left_quantity' => (float) $this->left_quantity,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
