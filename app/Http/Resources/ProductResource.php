<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'sub_category_id' => $this->sub_category_id,
            'sub_category_name' => optional($this->subCategory)->name,
            'category_id' => optional($this->subCategory)->category_id,
            'category_name' => optional(optional($this->subCategory)->category)->name,
            'name' => $this->name,
            'min_stock' => $this->min_stock,
            'status' => $this->is_active
        ];
    }
}
