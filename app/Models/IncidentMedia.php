<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentMedia extends Model
{
    use HasFactory;
    protected $fillable = [
        'incident_id',
        'file_path',
        'file_type'
    ];
}
