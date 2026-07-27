<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceAttachment extends Model
{
    protected $table = 'service_attachments';

    protected $fillable = [
        'service_record_id',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
        'uploaded_by',
    ];

    public function serviceRecord()
    {
        return $this->belongsTo(ServiceRecord::class, 'service_record_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
