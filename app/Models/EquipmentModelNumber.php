<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EquipmentModelNumber extends Model
{
    use HasFactory;

    protected $table = 'equipment_model_numbers';

    protected $fillable = [
        'equipment_id',
        'model_number',
        'manufacturer',
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
     * Machine instances registered under this model.
     */
    public function equipmentNames()
    {
        return $this->hasMany(
            EquipmentName::class,
            'equipment_model_number_id'
        );
    }
}
