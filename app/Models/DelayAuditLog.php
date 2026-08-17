<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DelayAuditLog extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'delay_audit_logs';

    protected $fillable = [
        'delay_id',
        'changed_by',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * Relationship to the delay entry.
     */
    public function delay()
    {
        return $this->belongsTo(Delay::class, 'delay_id');
    }

    /**
     * Relationship to the user who changed the record.
     */
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
