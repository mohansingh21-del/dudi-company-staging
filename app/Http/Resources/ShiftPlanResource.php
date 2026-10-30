<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShiftPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $supervisors = $this->resource->supervisorList();
        $primarySupervisor = $supervisors[0] ?? null;
        $siteInchargeEmployee = optional($this->siteIncharge)->employee;
        $creatorEmployee = optional($this->creator)->employee;
        $publisherEmployee = optional($this->publisher)->employee;

        $data = [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'planning_date' => $this->planning_date ? $this->planning_date->format('Y-m-d') : null,
            'shift_id' => $this->shift_id,
            'shift_name' => optional($this->shift)->shift_name,
            'site_id' => $this->site_id,
            'site_name' => optional($this->site)->site_name,
            'target_bcm' => !is_null($this->target_bcm) ? (string) round((float) $this->target_bcm, 2) : null,
            'actual_bcm' => !is_null($this->actual_bcm) ? (string) round((float) $this->actual_bcm, 2) : null,
            'supervisor_id' => $primarySupervisor ? $primarySupervisor['id'] : null,
            'supervisor_name' => $primarySupervisor ? $primarySupervisor['name'] : null,
            'supervisor_code' => $primarySupervisor ? $primarySupervisor['code'] : null,
            'supervisors' => $supervisors,
            'site_incharge_id' => $siteInchargeEmployee ? $siteInchargeEmployee->id : $this->site_incharge_id,
            'site_incharge_name' => $siteInchargeEmployee ? $siteInchargeEmployee->name : optional($this->siteIncharge)->email,
            'site_incharge_code' => $siteInchargeEmployee ? $siteInchargeEmployee->employee_code : null,
            'equipment_count' => (int) $this->equipment_count,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'creator_name' => $creatorEmployee ? $creatorEmployee->name : optional($this->creator)->email,
            'published_by' => $this->published_by,
            'publisher_name' => $publisherEmployee ? $publisherEmployee->name : optional($this->publisher)->email,
            'published_at' => $this->published_at ? $this->published_at->toDateTimeString() : null,
            'created_at' => $this->created_at ? $this->created_at->toDateTimeString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toDateTimeString() : null,
        ];

        if ($this->relationLoaded('equipmentAllocations')) {
            $allocations = $this->equipmentAllocations;

            // Separate top-level (no parent) from nested (has parent_equipment_id)
            $topLevel = $allocations->whereNull('parent_equipment_id')->values();
            $nested = $allocations->whereNotNull('parent_equipment_id')->values();

            $machineryAllocations = $topLevel->map(function ($allocation) use ($nested) {
                $machine = $allocation->equipmentName;
                $category = $machine ? $machine->equipment : null;

                // Category ID of this top-level machine
                $thisCategoryId = $category ? $category->id : null;

                // Find nested machines allocated under this specific parent machine ID
                $childAllocations = $nested->filter(function ($child) use ($machine) {
                    return $machine && $child->parent_equipment_id == $machine->id;
                })->values();

                $dumpers = $childAllocations->map(function ($child) {
                    $childMachine = $child->equipmentName;
                    return [
                        'allocation_id' => $child->id,
                        'machine_id' => $childMachine ? $childMachine->id : null,
                        'machine_number' => $childMachine ? $childMachine->equipment_name : null,
                        'model_number' => $childMachine ? optional($childMachine->modelNumber)->model_number : null,
                        'chassis_number' => $childMachine ? $childMachine->chassis_number : null,
                    ];
                });

                return [
                    'allocation_id' => $allocation->id,
                    'machine_id' => $machine ? $machine->id : null,
                    'machine_number' => $machine ? $machine->equipment_name : null,
                    'model_number' => $machine ? optional($machine->modelNumber)->model_number : null,
                    'chassis_number' => $machine ? $machine->chassis_number : null,
                    'category_id' => $thisCategoryId,
                    'category_name' => $category ? $category->name : null,
                    'dumpers' => $dumpers->toArray(),
                ];
            })->toArray();

            $data['machinery_allocations'] = $machineryAllocations;
        }

        return $data;
    }
}
