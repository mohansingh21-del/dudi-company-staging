<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TrainingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,

            'training_name' => $this->training_name,

            'training_type_id' => $this->trainingType ? $this->trainingType->id : null,
            'training_type' => $this->trainingType ? $this->trainingType->name : null,

            'supervisor_id' => $this->supervisor ? $this->supervisor->id : null,
            'supervisor_name' => $this->supervisor ? $this->supervisor->name : null,
            'supervisor_employee_code' => $this->supervisor ? $this->supervisor->employee_code : null,

            'start_date' => $this->start_date ? $this->start_date->format('Y-m-d') : null,
            'end_date' => $this->end_date ? $this->end_date->format('Y-m-d') : null,

            'status' => $this->is_active,

            'employee_count' => $this->whenLoaded(
                'employees',
                fn() => $this->employees->count()
            ),

            'employees' => $this->whenLoaded('employees', function () {
                return $this->employees->map(function ($employee) {
                    return [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'employee_code' => $employee->employee_code,
                        'designation' => $employee->designation
                            ? $employee->designation->name
                            : null,
                    ];
                });
            }),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
