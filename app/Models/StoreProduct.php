<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One product's stock at one outside store.
 *
 * `threshold` is this store's floor for this product and has nothing to do
 * with `products.min_stock`, which is the mine's own floor for the same
 * product. Both can be set, independently, on the same product.
 */
class StoreProduct extends Model
{
    use HasFactory;

    protected $table = 'store_products';

    protected $fillable = [
        'store_id',
        'product_id',
        'quantity',
        'left_quantity',
        'threshold',
        'is_active',
    ];

    protected $casts = [
        'quantity'      => 'decimal:2',
        'left_quantity' => 'decimal:2',
        'threshold'     => 'decimal:2',
        'is_active'     => 'integer',
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
