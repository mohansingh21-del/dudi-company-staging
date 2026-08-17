<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleAmc extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id', 'amc_provider', 'amc_number', 'amc_type', 'contract_number',
        'start_date', 'end_date', 'avg_running_year', 'cost', 'amc_gst', 'amc_tcs',
        'total_cost', 'payment_schedule', 'payment_start_date', 'document_path', 'status'
    ];

    public function getDocumentPathAttribute($value)
    {
        if (empty($value)) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        if (str_starts_with($value, 'storage/') || str_starts_with($value, '/storage/')) {
            return url(ltrim($value, '/'));
        }

        return url('storage/' . $value);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
