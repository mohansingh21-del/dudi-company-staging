<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceChecklistDetail extends Model
{
    protected $table = 'service_checklist_details';

    protected $fillable = [
        'service_record_id',
        'oil_change',
        'oil_change_amount',
        'hydraulic_oil',
        'hydraulic_oil_amount',
        'gear_oil',
        'gear_oil_amount',
        'fuel_filter_change',
        'fuel_filter_change_amount',
        'oil_filter_change',
        'oil_filter_change_amount',
    ];

    protected $casts = [
        'oil_change'                => 'boolean',
        'hydraulic_oil'             => 'boolean',
        'gear_oil'                  => 'boolean',
        'fuel_filter_change'        => 'boolean',
        'oil_filter_change'         => 'boolean',
        'oil_change_amount'         => 'decimal:2',
        'hydraulic_oil_amount'      => 'decimal:2',
        'gear_oil_amount'           => 'decimal:2',
        'fuel_filter_change_amount' => 'decimal:2',
        'oil_filter_change_amount'  => 'decimal:2',
    ];

    public function serviceRecord()
    {
        return $this->belongsTo(ServiceRecord::class, 'service_record_id');
    }
}
