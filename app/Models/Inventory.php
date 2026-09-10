<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock of one product at one store.
 *
 * Every row belongs to a store — there is no store-less "own" stock. The floor
 * a deduction may not cross is products.min_stock, the same number in every
 * store that carries the product.
 */
class Inventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'product_id',
        'quantity',
        'left_quantity',
        'is_active',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'left_quantity' => 'decimal:2',
        'is_active' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
