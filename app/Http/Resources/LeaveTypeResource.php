<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'leave_category' => $this->leave_category,
            'register_group' => $this->register_group,
            'register_group_label' => $this->register_group_label,

            // Annual quota credited as "Added" on the register, for every block.
            'Annual_limit' => $this->allowed_days,

            'status' => $this->is_active,
        ];
    }
}
