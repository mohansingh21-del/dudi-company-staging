<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleDriverMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'assignment_date',
        'shift',
        'status',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function getStatusAttribute($value)
    {
        return $value == 1 ? 'active' : 'inactive';
    }

    public function setStatusAttribute($value)
    {
        $this->attributes['status'] = ($value === 'active' || $value == 1) ? 1 : 0;
    }
}
