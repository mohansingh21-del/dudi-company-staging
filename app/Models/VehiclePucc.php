<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehiclePucc extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id', 'certificate_number', 'issue_date', 'valid_upto',
        'document_path', 'status'
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
