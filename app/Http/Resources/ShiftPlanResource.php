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
        $supervisorEmployee = optional($this->supervisor)->employee;
        $siteInchargeEmployee = optional($this->siteIncharge)->employee;
        $creatorEmployee = optional($this->creator)->employee;

        return [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'planning_date' => $this->planning_date ? $this->planning_date->format('Y-m-d') : null,
            'shift_id' => $this->shift_id,
            'shift_name' => optional($this->shift)->shift_name,
            'site_id' => $this->site_id,
            'site_name' => optional($this->site)->site_name,
            'target_bcm' => $this->target_bcm,
            'actual_bcm' => $this->actual_bcm,
            'supervisor_id' => $supervisorEmployee ? $supervisorEmployee->id : $this->supervisor_id,
            'supervisor_name' => $supervisorEmployee ? $supervisorEmployee->name : optional($this->supervisor)->email,
            'supervisor_code' => $supervisorEmployee ? $supervisorEmployee->employee_code : null,
            'site_incharge_id' => $siteInchargeEmployee ? $siteInchargeEmployee->id : $this->site_incharge_id,
            'site_incharge_name' => $siteInchargeEmployee ? $siteInchargeEmployee->name : optional($this->siteIncharge)->email,
            'site_incharge_code' => $siteInchargeEmployee ? $siteInchargeEmployee->employee_code : null,
            'equipment_count' => (int) $this->equipment_count,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'creator_name' => $creatorEmployee ? $creatorEmployee->name : optional($this->creator)->email,
            'created_at' => $this->created_at ? $this->created_at->toDateTimeString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toDateTimeString() : null,
        ];
    }
}
