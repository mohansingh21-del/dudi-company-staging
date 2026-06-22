<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
{
    public function toArray($request)
    {
        return [

            'id' => $this->id,

            'incident_no' => $this->incident_no,

            'incident_date' => $this->incident_date,

            'severity' => $this->severity,

            'status' => $this->status,

            'shift' => [
                'id' => $this->shift?->id,
                'name' => $this->shift?->name,
            ],

            'incident_type' => [
                'id' => $this->incidentType?->id,
                'incident_type' => $this->incidentType?->incident_type,
            ],

            'location' => [
                'id' => $this->location?->id,
                'name' => $this->location?->name,
            ],

            'person_involved' => $this->person ? [
                'id' => $this->person->id,
                'name' => $this->person->name,
                'employee_code' => $this->person->employee_code,
            ] : null,

            'incident_description' => $this->incident_description,

            'action_taken' => $this->action_taken,

            'preventive_measures' => $this->preventive_measures,

            'media' => $this->media->map(function ($file) {

                return [
                    'id' => $file->id,
                    'file' => asset('storage/' . $file->file_path),
                    'file_type' => $file->file_type,
                ];
            }),

            'created_at' => $this->created_at,

            'updated_at' => $this->updated_at,

        ];
    }
}
