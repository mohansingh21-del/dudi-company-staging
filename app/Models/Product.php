<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'sub_category_id',
        'name',
        'min_stock',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'integer',
        'min_stock' => 'integer',
    ];

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class, 'sub_category_id');
    }

    /**
     * This product's stock at each store that carries it — one row per store.
     */
    public function inventories()
    {
        return $this->hasMany(Inventory::class, 'product_id');
    }

    public function inventoryLogs()
    {
        return $this->hasMany(InventoryLog::class, 'product_id');
    }

    public function assignments()
    {
        return $this->hasMany(EmployeeProductAssignment::class, 'product_id');
    }
}
