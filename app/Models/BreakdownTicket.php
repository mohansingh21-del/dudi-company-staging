<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BreakdownTicket extends Model
{
    use HasFactory;

    protected $table = 'breakdown_tickets';

    protected $fillable = [
        'ticket_number',
        'shift_id',
        'equipment_id',
        'equipment_name_id',
        'equipment_allocation_id',
        'breakdown_date_time',
        'reported_by',
        'breakdown_type_id',
        'severity',
        'description',
        'status',
        'downtime_start',
        'downtime_end',
        'downtime_minutes',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'mine_site_id',
        'block_id',
        'date',
    ];

    protected $casts = [
        'breakdown_date_time'      => 'datetime',
        'downtime_start'           => 'datetime',
        'downtime_end'             => 'datetime',
        'resolved_at'              => 'datetime',
        'downtime_minutes'         => 'integer',
        'breakdown_type_id'        => 'integer',
        'shift_id'                 => 'integer',
        'equipment_id'             => 'integer',
        'equipment_name_id'        => 'integer',
        'equipment_allocation_id'  => 'integer',
        'reported_by'              => 'integer',
        'resolved_by'              => 'integer',
        'mine_site_id'             => 'integer',
        'block_id'                 => 'integer',
        'date'                     => 'date',
    ];

    protected static function boot()
    {
        parent::boot();
        static::saving(function ($model) {
            if (empty($model->date) && $model->breakdown_date_time) {
                $model->date = \Carbon\Carbon::parse($model->breakdown_date_time)->toDateString();
            }
            if (empty($model->mine_site_id) && $model->date && $model->shift_id) {
                $shiftPlan = \App\Models\ShiftPlan::where('shift_id', $model->shift_id)
                    ->where('planning_date', $model->date)
                    ->first();
                if ($shiftPlan) {
                    $model->mine_site_id = $shiftPlan->site_id;
                }
            }
        });
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function equipment()
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function equipmentName()
    {
        return $this->belongsTo(EquipmentName::class, 'equipment_name_id');
    }

    public function equipmentAllocation()
    {
        return $this->belongsTo(ShiftEquipmentAllocation::class, 'equipment_allocation_id');
    }

    public function reporter()
    {
        return $this->belongsTo(Employee::class, 'reported_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function breakdownType()
    {
        return $this->belongsTo(BreakdownType::class, 'breakdown_type_id');
    }
}
