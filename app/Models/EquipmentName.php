<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EquipmentName extends Model
{
    use HasFactory;

    protected $table = 'equipment_names';

    protected $fillable = [
        'equipment_id',
        'equipment_model_number_id',
        'equipment_name',
        'chassis_number',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'integer',
    ];

    // Nearly every API that shows a machine also shows its model number;
    // loading it here keeps those from querying it once per machine.
    protected $with = ['modelNumber'];


    public function equipment()
    {
        return $this->belongsTo(
            Equipment::class,
            'equipment_id'
        );
    }

    public function modelNumber()
    {
        return $this->belongsTo(
            EquipmentModelNumber::class,
            'equipment_model_number_id'
        );
    }

    /**
     * Shift allocations referencing this machine instance.
     */
    public function allocations()
    {
        return $this->hasMany(
            ShiftEquipmentAllocation::class,
            'equipment_name_id'
        );
    }
}
