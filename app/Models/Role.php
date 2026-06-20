<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'is_active'];

    protected $casts = [
        'is_active' => 'integer',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
    public function salaryStructure()
    {
        return $this->hasOne(SalaryStructure::class);
    }
}
