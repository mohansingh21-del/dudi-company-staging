<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id', 'dealer_invoice_number', 'invoice_date', 'GRN_date',
        'base_price', 'invoice_price', 'dealer_name', 'document_path', 'financed_by'
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
