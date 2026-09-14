<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray($request)
    {
        $leftQuantity = (float) $this->left_quantity;
        // The floor lives on the product and applies in every store that
        // carries it — there is no per-store override.
        $minStock = (float) optional($this->product)->min_stock;

        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'store_name' => optional($this->store)->name,
            'product_id' => $this->product_id,
            'product_name' => optional($this->product)->name,
            'category_name' => optional(optional(optional($this->product)->subCategory)->category)->name,
            'sub_category_name' => optional(optional($this->product)->subCategory)->name,
            'total_stock' => (float) $this->quantity,
            'left_quantity' => $leftQuantity,
            'min_stock' => $minStock,
            // What can actually be issued: everything on the shelf. min_stock
            // only raises the low-stock flag, it does not hold stock back.
            'available_quantity' => max(0, $leftQuantity),
            'is_low_stock' => $leftQuantity <= $minStock,
            'is_out_of_stock' => $leftQuantity <= 0,
            'status' => $this->is_active,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
