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
        'equipment_name',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'integer',
    ];


    public function equipment()
    {
        return $this->belongsTo(
            Equipment::class,
            'equipment_id'
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
