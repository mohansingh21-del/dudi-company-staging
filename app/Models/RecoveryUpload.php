<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecoveryUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'file_name',
        'uploaded_by',
        'status',
        'total_rows',
        'error_rows',
    ];

    public function rows()
    {
        return $this->hasMany(
            RecoveryUploadRow::class,
            'recovery_upload_id'
        );
    }

    /*
     * Main recovery records created after Submit.
     */
    public function recoveries()
    {
        return $this->hasMany(
            Recovery::class,
            'recovery_upload_id'
        );
    }

    public function user()
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by'
        );
    }
}
