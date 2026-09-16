<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class InventoryAlertResource extends JsonResource
{
    public function toArray($request)
    {
        $decimal = function ($value) {
            return $value === null ? null : (float) $value;
        };

        $user = function ($user) {
            return $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null;
        };

        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'store_id' => $this->store_id,
            'store_name' => $this->store_name,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'inventory_id' => $this->inventory_id,
            'quantity' => $decimal($this->quantity),
            'left_quantity' => $decimal($this->left_quantity),
            'min_stock' => $decimal($this->min_stock),
            'source' => $this->source,
            'reference' => $this->reference,
            'meta' => $this->meta,
            'is_read' => $this->read_at !== null,
            'read_at' => optional($this->read_at)->toIso8601String(),
            'read_by' => $user($this->readBy),
            // Only level alerts resolve; for every other type this is null
            // rather than a misleading false.
            'is_resolved' => $this->resource->isLevel() ? $this->resolved_at !== null : null,
            'resolved_at' => optional($this->resolved_at)->toIso8601String(),
            'triggered_by' => $user($this->triggeredBy),
            // Where clicking the alert should take the user.
            'redirect' => $this->resource->redirect(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
