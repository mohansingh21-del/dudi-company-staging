<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image',
        'country_id',
        'is_active',
        'latitude',
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

    public function cities()
    {
        return $this->hasMany(City::class);
    }
}
