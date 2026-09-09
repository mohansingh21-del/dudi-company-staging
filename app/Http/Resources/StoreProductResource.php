<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StoreProductResource extends JsonResource
{
    public function toArray($request)
    {
        $leftQuantity = (float) $this->left_quantity;
        $threshold = (float) $this->threshold;

        return [
            'id'                => $this->id,
            'store_id'          => $this->store_id,
            'store_name'        => optional($this->store)->name,
            'product_id'        => $this->product_id,
            'product_name'      => optional($this->product)->name,
            'category_name'     => optional(optional(optional($this->product)->subCategory)->category)->name,
            'sub_category_name' => optional(optional($this->product)->subCategory)->name,
            'total_stock'       => (float) $this->quantity,
            'left_quantity'     => $leftQuantity,
            'threshold'         => $threshold,
            // What can actually be issued: the threshold is a hard floor, so
            // stock sitting at or under it is not available.
            'available_quantity' => max(0, $leftQuantity - $threshold),
            'is_low_stock'      => $leftQuantity <= $threshold,
            'status'            => $this->is_active,
            'created_at'        => optional($this->created_at)->toIso8601String(),
            'updated_at'        => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
