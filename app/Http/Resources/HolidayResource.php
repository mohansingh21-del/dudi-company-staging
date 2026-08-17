<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HolidayResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'holiday_name' => $this->holiday_name,
            'holiday_date' => $this->formatted_date,
            'holiday_type' => $this->holiday_type,
            'site' => optional($this->site)->site_name,
            'status' => $this->is_active,
        ];
    }
}
