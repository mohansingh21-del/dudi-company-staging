<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelayCategory extends Model
{
    protected $fillable = [
        'delay_category',
        'description',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'integer'
    ];
}
