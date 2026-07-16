<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    use HasFactory;
    protected $table = 'incidents';
    protected $fillable = [

        'incident_no',
        'incident_date',
        'shift_id',
        'shift_plan_id',
        'incident_type_id',
        'severity',
        'status',
        'location_id',
        'equipment_id',
        'equipment_name_id',
        'person_involved_id',
        'incident_description',
        'action_taken',
        'preventive_measures'

    ];


    public function shiftPlan()
    {
        return $this->belongsTo(ShiftPlan::class, 'shift_plan_id');
    }


    public function media()
    {
        return $this->hasMany(IncidentMedia::class);
    }


    public function incidentType()
    {
        return $this->belongsTo(IncidentType::class);
    }


    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }


    public function location()
    {
        return $this->belongsTo(Site::class);
    }


    public function person()
    {
        return $this->belongsTo(Employee::class, 'person_involved_id');
    }
    public function equipment()
    {
        return $this->belongsTo(Equipment::class);
    }

    public function equipmentName()
    {
        return $this->belongsTo(
            EquipmentName::class,
            'equipment_name_id'
        );
    }
}
