<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehiclePermit extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id', 'permit_type', 'state', 'permit_number', 'permit_holder',
        'father_name', 'address', 'registration_date', 'payment_terms',
        'compliance_document', 'document_charges', 'NP_dated', 'valid_from',
        'valid_upto', 'document_path', 'status'
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
