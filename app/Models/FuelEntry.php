<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FuelEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'transaction_date',
        'fuel_station',
        'quantity',
        'amount',
        'fuel_type',
        'current_odometer',
        'mileage'
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'quantity' => 'decimal:2',
        'amount' => 'decimal:2',
        'current_odometer' => 'integer',
        'mileage' => 'decimal:2',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }
}
