<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\GeneralVehicleDetails;

class VehicleManufacturer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    public function vehicleTypes()
    {
        return $this->belongsToMany(VehicleType::class, 'vehicle_type_has_vehicle_manufacturer', 'vehicle_manufacturer_id', 'vehicle_type_id');
    }

    public function models()
    {
        return $this->hasMany(VehicleModel::class);
    }

    public function generalVehicleDetails()
    {
        return $this->hasMany(
            Vehicle::class
        );
    }
}
