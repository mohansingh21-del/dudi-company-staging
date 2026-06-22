<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentType extends Model
{

    protected $fillable = [

        'incident_type',
        'description',
        'is_active'

    ];


    protected $casts = [

        'is_active' => 'integer'

    ];
}
