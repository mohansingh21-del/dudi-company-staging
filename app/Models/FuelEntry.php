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
        'fuel_log_date',
        'shift_id',
        'equipment_allocation_id',
        'equipment_id',
        'equipment_name_id',
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
        'mine_site_id',
        'block_id',
        'date',
    ];

    protected $casts = [
        'fuel_log_date' => 'datetime',
        'opening_fuel' => 'decimal:2',
        'fuel_issued' => 'decimal:2',
        'closing_fuel' => 'decimal:2',
        'fuel_consumption' => 'decimal:2',
        'work_done_bcm' => 'decimal:2',
        'fuel_per_bcm' => 'decimal:4',
        'fuel_per_hour' => 'decimal:4',
        'fuel_per_km' => 'decimal:4',
        'mine_site_id' => 'integer',
        'block_id' => 'integer',
        'date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();
        static::saving(function ($model) {
            if (empty($model->mine_site_id) && $model->shiftPlan) {
                $model->mine_site_id = $model->shiftPlan->site_id;
            }
            if (empty($model->date) && $model->fuel_log_date) {
                $model->date = \Carbon\Carbon::parse($model->fuel_log_date)->toDateString();
            }
        });
    }

    protected $appends = [
        'shift_name',
    ];

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

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
     * The shift this entry belongs to.
     */
    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /**
     * The equipment allocation this entry belongs to.
     */
    public function equipmentAllocation()
    {
        return $this->belongsTo(ShiftEquipmentAllocation::class, 'equipment_allocation_id');
    }

    /**
     * The equipment category this entry belongs to.
     */
    public function equipment()
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    /**
     * The specific equipment name/instance this entry belongs to.
     */
    public function equipmentName()
    {
        return $this->belongsTo(EquipmentName::class, 'equipment_name_id');
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

    /**
     * Get shift_name attribute dynamically.
     */
    public function getShiftNameAttribute()
    {
        if ($this->relationLoaded('shift') && $this->shift) {
            return $this->shift->shift_name;
        }
        if ($this->relationLoaded('shiftPlan') && $this->shiftPlan && $this->shiftPlan->relationLoaded('shift') && $this->shiftPlan->shift) {
            return $this->shiftPlan->shift->shift_name;
        }
        return optional($this->shift)->shift_name ?? optional(optional($this->shiftPlan)->shift)->shift_name;
    }
}
