<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Machine extends Model
{
    protected $table = 'equipment_names';

    protected $guarded = [];

    public function equipment()
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function modelNumber()
    {
        return $this->belongsTo(EquipmentModelNumber::class, 'equipment_model_number_id');
    }
}
