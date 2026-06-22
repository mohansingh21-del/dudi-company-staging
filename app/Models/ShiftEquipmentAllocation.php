<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftEquipmentAllocation extends Model
{
    use HasFactory;

    protected $table = 'shift_equipment_allocations';

    protected $fillable = [
        'shift_plan_id',
        'equipment_name_id',
        'parent_equipment_id',
        'allocated_by',
        'allocation_time',
    ];

    protected $casts = [
        'allocation_time' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The shift plan this allocation belongs to.
     */
    public function shiftPlan()
    {
        return $this->belongsTo(ShiftPlan::class, 'shift_plan_id');
    }

    /**
     * The actual machine instance (equipment_names row).
     */
    public function equipmentName()
    {
        return $this->belongsTo(EquipmentName::class, 'equipment_name_id');
    }

    /**
     * The parent category this machine is nested under (nullable).
     * e.g. Excavator's equipments.id when allocating a Dumper underneath it.
     */
    public function parentCategory()
    {
        return $this->belongsTo(Equipment::class, 'parent_equipment_id');
    }

    /**
     * The user who performed the allocation.
     */
    public function allocator()
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }
}
