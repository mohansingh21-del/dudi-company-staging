<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceStatusHistory extends Model
{
    protected $table = 'service_status_history';

    public $timestamps = false;

    protected $fillable = [
        'service_record_id',
        'from_status',
        'to_status',
        'remarks',
        'changed_by',
        'created_at',
    ];

    public function serviceRecord()
    {
        return $this->belongsTo(ServiceRecord::class, 'service_record_id');
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
