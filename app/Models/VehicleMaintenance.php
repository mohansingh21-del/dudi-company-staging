<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleMaintenance extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'service_type',
        'service_date',
        'cost',
        'kilometer_reading',
        'vendor_name',
        'next_service_due_date',
        'next_service_km'
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
