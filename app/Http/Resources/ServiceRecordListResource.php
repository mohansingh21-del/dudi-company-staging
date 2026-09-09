<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Slim payload for the service record listing.
 *
 * Only the columns a table row actually renders. Checklist, spare parts,
 * attachments, status history and audit logs are intentionally left out —
 * they are available on GET /service-records/{id}.
 */
class ServiceRecordListResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                   => $this->id,
            'ticket_number'        => $this->ticket_number,
            'machine_id'           => $this->machine_id,
            'machine_name'         => optional($this->machine)->equipment_name,
            'site_id'              => $this->site_id,
            'site_name'            => optional($this->site)->site_name,
            'is_breakdown_service' => (bool) $this->is_breakdown_service,
            'breakdown_id'         => $this->breakdown_id,
            'breakdown_ticket'     => optional($this->breakdown)->ticket_number,
            'service_type'         => $this->service_type,
            'service_date'         => $this->service_date ? $this->service_date->toDateString() : null,
            'downtime_minutes'     => $this->downtime_minutes,
            'status'               => $this->status,
            'total_amount'         => (float) $this->total_amount,
            'performed_by'         => $this->performed_by,
            // The users table has no name column, so email is the only label available.
            'created_by_email'     => optional($this->creator)->email,
            'created_at'           => optional($this->created_at)->toDateTimeString(),
        ];
    }
}
