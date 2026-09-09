<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class IncidentTypeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,

            'incident_type' => $this->incident_type,

            'description' => $this->description,

            'status' => $this->is_active,
        ];
    }
}
