<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RelayResource extends JsonResource
{
    public function toArray($request)
    {
        $today = now()->toDateString();
        $mapping = \App\Models\RelayShiftMapping::where('relay_id', $this->id)
            ->where('week_start_date', '<=', $today)
            ->where('week_end_date', '>=', $today)
            ->first();

        if (!$mapping) {
            $mapping = \App\Models\RelayShiftMapping::where('relay_id', $this->id)
                ->orderBy('week_start_date', 'desc')
                ->first();
        }

        $shift = $mapping ? $mapping->shift : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_rotating' => $this->is_rotating,
            'status' => $this->is_active,
            'shift_id' => $shift ? $shift->id : null,
            'shift_name' => $shift ? $shift->shift_name : null,
            'created_at' => $this->created_at ? $this->created_at->toDateTimeString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toDateTimeString() : null,
        ];
    }
}
