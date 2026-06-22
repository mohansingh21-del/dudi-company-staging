<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EquipmentName extends Model
{
    use HasFactory;

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
}
