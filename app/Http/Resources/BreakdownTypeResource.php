<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BreakdownTypeResource extends JsonResource
{
    public function toArray($request)
    {
        return [

            'id' => $this->id,

            'breakdown_type' => $this->breakdown_type,

            'description' => $this->description,

            'status' => $this->is_active

        ];
    }
}
