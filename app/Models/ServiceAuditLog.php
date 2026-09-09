<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceAuditLog extends Model
{
    protected $table = 'service_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'service_record_id',
        'action',
        'changes',
        'performed_by',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function serviceRecord()
    {
        return $this->belongsTo(ServiceRecord::class, 'service_record_id');
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
