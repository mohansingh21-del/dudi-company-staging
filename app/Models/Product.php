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
     * The mine's own stock of this product. Outside stores hold the same
     * product separately — see storeProducts().
     */
    public function inventory()
    {
        return $this->hasOne(Inventory::class, 'product_id');
    }

    /**
     * This product's stock at each outside store that carries it.
     */
    public function storeProducts()
    {
        return $this->hasMany(StoreProduct::class, 'product_id');
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
