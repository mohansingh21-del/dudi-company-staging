<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'vehicle_manufacturer_id',
        'vehicle_type_id',
        'is_active',
    ];

    public function vehicleManufacturer()
    {
        return $this->belongsTo(VehicleManufacturer::class, 'vehicle_manufacturer_id');
    }

    public function vehicleType()
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }
}
