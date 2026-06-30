<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Delay extends Model
{
    use HasFactory;

    protected $table = 'delays';

    protected $fillable = [
        'delay_ref_no',
        'shift_plan_id',
        'shift_id',
        'shift_date',
        'shift_name',
        'delay_log_date',
        'delay_category_id',
        'delay_subcategory',
        'start_time',
        'end_time',
        'duration_minutes',
        'severity',
        'linked_breakdown_id',
        'equipment_id',
        'equipment_name_id',
        'average_production_rate_per_hour',
        'estimated_production_loss_bcm',
        'description',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'delay_log_date' => 'datetime',
        'duration_minutes' => 'integer',
        'average_production_rate_per_hour' => 'decimal:2',
        'estimated_production_loss_bcm' => 'decimal:2',
        'delay_category_id' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    |
    */

    public function delayCategory()
    {
        return $this->belongsTo(DelayCategory::class, 'delay_category_id');
    }

    public function shiftPlan()
    {
        return $this->belongsTo(ShiftPlan::class, 'shift_plan_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function linkedBreakdown()
    {
        return $this->belongsTo(BreakdownTicket::class, 'linked_breakdown_id');
    }

    public function equipment()
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function equipmentName()
    {
        return $this->belongsTo(EquipmentName::class, 'equipment_name_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function auditLogs()
    {
        return $this->hasMany(DelayAuditLog::class, 'delay_id');
    }
}
