<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleInsurance extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id', 'provider', 'policy_number', 'insurance_type', 'issue_date',
        'expiry_date', 'od_premium_amt', 'od_deductions', 'od_additions',
        'total_od_premium', 'tp_premium', 'tp_deductions', 'tp_additions',
        'total_tp_premium', 'total_premium', 'gst_amount', 'cess_amount', 'total',
        'nominee_name', 'relation', 'age', 'document_path', 'status'
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
