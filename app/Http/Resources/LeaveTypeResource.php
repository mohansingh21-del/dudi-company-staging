<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'leave_category' => $this->leave_category,
            'Annual_limit' => $this->allowed_days,
            'status' => $this->is_active,


        ];
    }
}
