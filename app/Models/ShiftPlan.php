<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftPlan extends Model
{
    use HasFactory;

    protected $table = 'shift_plans';

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
    ];

    protected $casts = [
        'planning_date' => 'date',
        'target_bcm'    => 'decimal:2',
        'actual_bcm'    => 'decimal:2',
        'equipment_count' => 'integer',
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

    public function equipmentAllocations()
    {
        return $this->hasMany(ShiftEquipmentAllocation::class, 'shift_plan_id');
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
        return $query->whereNotIn('status', ['closed']);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'planned']);
    }
}
