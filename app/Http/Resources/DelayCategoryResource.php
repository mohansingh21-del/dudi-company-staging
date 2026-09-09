<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DelayCategoryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'delay_category' => $this->delay_category,
            'description' => $this->description,
            'status' => $this->is_active
        ];
    }
}
