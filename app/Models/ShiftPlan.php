<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftPlan extends Model
{
    use HasFactory;

    protected $table = 'shift_plans';

    /**
     * Largest value the decimal(10,2) BCM columns can hold.
     */
    const MAX_BCM = 99999999.99;

    protected $fillable = [
        'planning_date',
        'shift_id',
        'site_id',
        'target_bcm',
        'supervisor_id',
        'site_incharge_id',
        'equipment_count',
        'status',
        'created_by',
        'reference_no',
        'actual_bcm',
        'published_by',
        'published_at',
        'closed_by',
        'closure_date',
        'closure_time',
        'supervisor_remarks',
        'handover_notes',
        'closure_confirmed',
        'breakdown_justification',
        'shift_summary_snapshot',
    ];

    protected $casts = [
        'planning_date'     => 'date',
        'target_bcm'        => 'decimal:2',
        'actual_bcm'        => 'decimal:2',
        'equipment_count'   => 'integer',
        'published_at'      => 'datetime',
        'closure_date'      => 'date',
        'closure_confirmed' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function siteIncharge()
    {
        return $this->belongsTo(User::class, 'site_incharge_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function equipmentAllocations()
    {
        return $this->hasMany(ShiftEquipmentAllocation::class, 'shift_plan_id');
    }

    public function workforceDeployments()
    {
        return $this->hasMany(ShiftWorkforceDeployment::class, 'shift_plan_id');
    }

    public function closureAuditLogs()
    {
        return $this->hasMany(ShiftClosureAuditLog::class, 'shift_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Shifts that are NOT closed — considered "active" for allocation exclusion.
     */
    public function scopeNotClosed($query)
    {
        return $query->whereNotIn('status', ['closed', 'completed']);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'planned']);
    }
}
