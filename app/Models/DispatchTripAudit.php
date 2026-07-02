<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DispatchTripAudit extends Model
{
    use HasFactory;

    protected $table = 'dispatch_trip_audits';

    protected $fillable = [
        'dispatch_trip_id',
        'changed_fields',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'changed_fields' => 'array',
        'changed_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function dispatchTrip()
    {
        return $this->belongsTo(DispatchTrip::class, 'dispatch_trip_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
