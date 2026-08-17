<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->shift_name,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'minimum_working_hours' => $this->minimum_working_hours,
            'is_night_shift' => $this->is_night_shift,
            'status' => $this->is_active,


        ];
    }
}
