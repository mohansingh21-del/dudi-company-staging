<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock of one product at one store.
 *
 * Every row belongs to a store — there is no store-less "own" stock. Stock can
 * be issued down to zero. products.min_stock, the same number in every store
 * that carries the product, is only the line below which an alert is raised.
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

    public const STOCK_STATUS_LABELS = [
        'in_stock' => 'In Stock',
        'low_stock' => 'Low Stock',
        'out_of_stock' => 'Out of Stock',
    ];

    /**
     * Request keys scopeListFilter understands.
     */
    public const LIST_FILTERS = [
        'store_id',
        'product_id',
        'category_id',
        'sub_category_id',
        'low_stock',
        'stock_status',
        'search',
    ];

    /**
     * The Stock Status of a row, using the same three non-overlapping states
     * as the stock_status filter on the inventory list, so a row always shows
     * the status it would be found under:
     *   out_of_stock — nothing left on the shelf at all
     *   low_stock    — some left, but at or under min_stock (still issuable)
     *   in_stock     — above min_stock
     *
     * @param  float  $leftQuantity
     * @param  float  $minStock
     * @return string
     */
    public static function stockStatus($leftQuantity, $minStock)
    {
        if ((float) $leftQuantity <= 0) {
            return 'out_of_stock';
        }

        return (float) $leftQuantity <= (float) $minStock ? 'low_stock' : 'in_stock';
    }

    /**
     * The inventory list's filters, shared by the list screen and its export so
     * a downloaded file always holds exactly the rows the screen was showing.
     *
     * Accepts: store_id, product_id, category_id, sub_category_id, low_stock,
     * stock_status (in_stock | low_stock | out_of_stock) and search. Blank
     * values are ignored.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeListFilter($query, array $filters)
    {
        $filled = fn ($key) => isset($filters[$key]) && $filters[$key] !== '';

        if ($filled('store_id')) {
            $query->where('store_id', (int) $filters['store_id']);
        }

        if ($filled('product_id')) {
            $query->where('product_id', (int) $filters['product_id']);
        }

        if ($filled('sub_category_id')) {
            $subCategoryId = (int) $filters['sub_category_id'];
            $query->whereHas('product', function ($q) use ($subCategoryId) {
                $q->where('sub_category_id', $subCategoryId);
            });
        }

        // Category is a grandparent here — stock points at a product, which
        // points at a sub-category, which points at the category.
        if ($filled('category_id')) {
            $categoryId = (int) $filters['category_id'];
            $query->whereHas('product.subCategory', function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId);
            });
        }

        // Stock sitting at or under its product's min_stock, which lives on
        // products, so this has to reach across the join rather than compare
        // two columns of this table.
        if ($filled('low_stock') && filter_var($filters['low_stock'], FILTER_VALIDATE_BOOLEAN)) {
            $query->whereHas('product', function ($q) {
                $q->whereColumn('products.min_stock', '>=', 'inventories.left_quantity');
            });
        }

        // The Stock Status dropdown. Three states that do not overlap and
        // together cover every row, matching stockStatus() above.
        if ($filled('stock_status')) {
            switch ($filters['stock_status']) {
                case 'out_of_stock':
                    $query->where('left_quantity', '<=', 0);
                    break;

                case 'low_stock':
                    $query->where('left_quantity', '>', 0)
                        ->whereHas('product', function ($q) {
                            $q->whereColumn('products.min_stock', '>=', 'inventories.left_quantity');
                        });
                    break;

                case 'in_stock':
                    $query->whereHas('product', function ($q) {
                        $q->whereColumn('products.min_stock', '<', 'inventories.left_quantity');
                    });
                    break;
            }
        }

        if ($filled('search')) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('product', function ($pq) use ($search) {
                    $pq->where('name', 'LIKE', "%{$search}%")
                        ->orWhereHas('subCategory', function ($sq) use ($search) {
                            $sq->where('name', 'LIKE', "%{$search}%")
                                ->orWhereHas('category', function ($cq) use ($search) {
                                    $cq->where('name', 'LIKE', "%{$search}%");
                                });
                        });
                })->orWhereHas('store', function ($sq) use ($search) {
                    $sq->where('name', 'LIKE', "%{$search}%");
                });
            });
        }

        return $query;
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
