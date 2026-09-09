<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Relay extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'is_rotating',
        'is_active',
    ];

    protected $casts = [
        'is_rotating' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function shiftMappings()
    {
        return $this->hasMany(RelayShiftMapping::class);
    }

    public function getCurrentShiftIdAttribute()
    {
        $today = now()->toDateString();
        $mapping = $this->shiftMappings()
            ->where('week_start_date', '<=', $today)
            ->where('week_end_date', '>=', $today)
            ->first();

        if (!$mapping) {
            $mapping = $this->shiftMappings()
                ->orderBy('week_start_date', 'desc')
                ->first();
        }

        return $mapping ? $mapping->shift_id : null;
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
