<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BreakdownType extends Model
{
    protected $fillable = [
        'breakdown_type',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'integer'
    ];
}
