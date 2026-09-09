<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FuelEntryAuditLog extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'fuel_entry_id',
        'changed_by',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * Relationship to the fuel entry.
     */
    public function fuelEntry()
    {
        return $this->belongsTo(FuelEntry::class, 'fuel_entry_id');
    }

    /**
     * Relationship to the user who changed the record.
     */
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
