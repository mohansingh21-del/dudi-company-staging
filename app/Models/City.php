<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image',
        'state_id',
        'country_id',
        'latitude',
        'is_active',
        'longitude',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function getImageAttribute($value)
    {
        if (empty($value)) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        if (str_starts_with($value, 'storage/') || str_starts_with($value, '/storage/')) {
            return url(ltrim($value, '/'));
        }

        if (str_starts_with($value, 'uploads/') || str_starts_with($value, '/uploads/')) {
            return url(ltrim($value, '/'));
        }

        return url('storage/' . $value);
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }
}
