<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PenaltyResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => optional($this->employee)->name,
            'employee_code' => optional($this->employee)->employee_code,
            'penalty_date' => $this->penalty_date ? $this->penalty_date->format('Y-m-d') : null,
            'penalty_month' => $this->penalty_date ? $this->penalty_date->format('F Y') : null,
            'month' => $this->month,
            'year' => $this->year,
            'reason' => $this->reason,
            'amount' => $this->amount,
            'created_at' => $this->created_at ? $this->created_at->toDateTimeString() : null,
        ];
    }
}
