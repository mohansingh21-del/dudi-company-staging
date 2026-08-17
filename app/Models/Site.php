<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    use HasFactory;
    protected $fillable = ['site_name', 'address', 'is_active'];
    public function holidays()
    {
        return $this->hasMany(Holiday::class);
    }
}
