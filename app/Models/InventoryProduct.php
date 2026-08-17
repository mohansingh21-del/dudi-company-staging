<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryProduct extends Model
{
    protected $table = 'products';

    protected $guarded = [];

    public function inventory()
    {
        return $this->hasOne(Inventory::class, 'product_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class, 'sub_category_id');
    }
}
