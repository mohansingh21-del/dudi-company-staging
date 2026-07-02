<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SitePointResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'site_id'     => $this->site_id,
            'site_name'   => $this->site ? $this->site->site_name : null,
            'name'        => $this->name,
            'type'        => $this->type,
            'description' => $this->description,
            'latitude'    => $this->latitude,
            'longitude'   => $this->longitude,
            'is_active'   => $this->is_active,
            'created_by'  => $this->created_by,
            'creator_name'=> $this->creator ? $this->creator->name : null,
            'created_at'  => $this->created_at ? $this->created_at->toDateTimeString() : null,
            'updated_at'  => $this->updated_at ? $this->updated_at->toDateTimeString() : null,
        ];
    }
}
