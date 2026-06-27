<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FuelEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'fuel_ref_no',
        'shift_plan_id',
        'equipment_allocation_id',
        'operator_id',
        'fuel_source',
        'opening_fuel',
        'fuel_issued',
        'closing_fuel',
        'fuel_consumption',
        'work_done_bcm',
        'fuel_per_bcm',
        'hours_meter_reading',
        'kilometer_reading',
        'fuel_per_hour',
        'fuel_per_km',
        'remarks',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'opening_fuel' => 'decimal:2',
        'fuel_issued' => 'decimal:2',
        'closing_fuel' => 'decimal:2',
        'fuel_consumption' => 'decimal:2',
        'work_done_bcm' => 'decimal:2',
        'fuel_per_bcm' => 'decimal:4',
        'fuel_per_hour' => 'decimal:4',
        'fuel_per_km' => 'decimal:4',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope query to a specific machine equipment allocation.
     */
    public function scopeForMachine($query, $equipmentAllocationId)
    {
        return $query->where('equipment_allocation_id', $equipmentAllocationId);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The shift plan this entry belongs to.
     */
    public function shiftPlan()
    {
        return $this->belongsTo(ShiftPlan::class, 'shift_plan_id');
    }

    /**
     * The equipment allocation this entry belongs to.
     */
    public function equipmentAllocation()
    {
        return $this->belongsTo(ShiftEquipmentAllocation::class, 'equipment_allocation_id');
    }

    /**
     * The operator associated with the fuel entry.
     */
    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * The user who created the fuel entry.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who updated the fuel entry.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The audit logs for this fuel entry.
     */
    public function auditLogs()
    {
        return $this->hasMany(FuelEntryAuditLog::class, 'fuel_entry_id');
    }
}
