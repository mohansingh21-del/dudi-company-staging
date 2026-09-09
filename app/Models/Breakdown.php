<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Breakdown extends Model
{
    protected $table = 'breakdown_tickets';

    protected $guarded = [];

    protected $casts = [
        'breakdown_date_time' => 'datetime',
        'downtime_start'      => 'datetime',
        'downtime_end'        => 'datetime',
        'resolved_at'         => 'datetime',
        'date'                => 'date',
    ];

    public function equipment()
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function equipmentName()
    {
        return $this->belongsTo(EquipmentName::class, 'equipment_name_id');
    }
}
