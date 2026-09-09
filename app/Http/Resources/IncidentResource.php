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

          'shift_id' => $this->shift ? $this->shift->id : null,
          'shift_name' => $this->shift ? $this->shift->shift_name : null,

          'location_id' => $this->location ? $this->location->id : null,
          'location_name' => $this->location ? $this->location->site_name : null,

          'incident_type_id' => $this->incidentType ? $this->incidentType->id : null,
          'incident_type' => $this->incidentType ? $this->incidentType->incident_type : null,

          'equipment_id' => $this->equipment ? $this->equipment->id : null,
          'equipment_category' => $this->equipment ? $this->equipment->name : null,

          'equipment_name_id' => $this->equipmentName ? $this->equipmentName->id : null,
          'equipment__name' => $this->equipmentName ? $this->equipmentName->equipment_name : null,

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
