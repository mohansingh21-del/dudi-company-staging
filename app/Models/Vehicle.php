<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_number',
        'owner_name',
        'is_active',
        'vehicle_type_id',
        'vehicle_body_type',
        'vehicle_length',
        'vehicle_condition',
        'vehicle_manufacturer_id',
        'vehicle_model_id',
        'registration_date',
        'body_total_volumetric_capacity',
        'chassis_number',
        'engine_number',
        'color',
        'wheel_base',
        'emission_norm',
        'horse_power',
        'laden_weight',
        'unladen_weight',
        'gross_vehicle_weight',
        'fuel_type',
    ];

    public function vehicleType()
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function vehicleManufacturer()
    {
        return $this->belongsTo(VehicleManufacturer::class);
    }

    public function vehicleModel()
    {
        return $this->belongsTo(VehicleModel::class);
    }

    public function invoices()
    {
        return $this->hasMany(VehicleInvoice::class);
    }

    public function amcs()
    {
        return $this->hasMany(VehicleAmc::class);
    }

    public function permits()
    {
        return $this->hasMany(VehiclePermit::class);
    }

    public function insurances()
    {
        return $this->hasMany(VehicleInsurance::class);
    }

    public function puccs()
    {
        return $this->hasMany(VehiclePucc::class);
    }

    public function fitnessCertificates()
    {
        return $this->hasMany(VehicleFitnessCertificate::class);
    }

    public function documents()
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function driverMappings()
    {
        return $this->hasMany(VehicleDriverMapping::class);
    }

    public function maintenances()
    {
        return $this->hasMany(VehicleMaintenance::class);
    }
}
