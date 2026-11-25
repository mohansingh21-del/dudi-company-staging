<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use HasFactory;

    protected $table = 'stores';

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'integer',
    ];

    /**
     * The stock this store holds, one row per product.
     */
    public function inventories()
    {
        return $this->hasMany(Inventory::class, 'store_id');
    }
}
