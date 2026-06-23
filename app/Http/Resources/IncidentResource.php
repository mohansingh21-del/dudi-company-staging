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

            'shift_id' => $this->shift?->id,
            'shift_name' => $this->shift?->shift_name,


            'location_id' => $this->location?->id,
            'location_name' => $this->location?->site_name,
            'incident_type_id' => $this->incidentType?->id,
            'incident_type' => $this->incidentType?->incident_type,



            'equipment_id' => $this->equipment?->id,
            'equipment_category' => $this->equipment?->name,


            'equipment_name_id' => $this->equipmentName?->id,
            'equipment__name' => $this->equipmentName?->equipment_name,

            'person_involved_id' => $this->person->id,
            'person_involved_name' => $this->person->name,
            'person_involved_employee_code' => $this->person->employee_code,


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
