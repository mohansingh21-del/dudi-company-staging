<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'holiday_name',
        'holiday_date',
        'site_id',
        'holiday_type',
        'is_active',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];

    /**
     * Relationship: Holiday belongs to a Site
     */
    public function site()
    {
        return $this->belongsTo(Site::class);
    }
    public function getFormattedDateAttribute()
    {
        return optional($this->holiday_date)->format('d-M-Y');
    }
}
