<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceSparePart extends Model
{
    protected $table = 'service_spare_parts';

    protected $fillable = [
        'service_record_id',
        'inventory_id',
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

    /**
     * The stock row this part came out of.
     *
     * Null on rows written before the two inventories were merged, and on the
     * older free-text vendor rows. Both keep part_name / vendor_name, which is
     * what the report falls back to.
     */
    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }
}
