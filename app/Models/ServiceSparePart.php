<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceSparePart extends Model
{
    protected $table = 'service_spare_parts';

    protected $fillable = [
        'service_record_id',
        'source',
        'inventory_product_id',
        'part_name',
        'vendor_name',
        'quantity',
        'unit_price',
        'amount',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount'     => 'decimal:2',
    ];

    public function serviceRecord()
    {
        return $this->belongsTo(ServiceRecord::class, 'service_record_id');
    }

    public function inventoryProduct()
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }
}
