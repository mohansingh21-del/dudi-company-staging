<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the inventory alert feed. Written by InventoryAlertService.
 *
 * Level alerts (low_stock, out_of_stock) track a stock row's current state and
 * get resolved_at once it recovers. Every other type is a one-off event.
 */
class InventoryAlert extends Model
{
    use HasFactory;

    const TYPE_LOW_STOCK = 'low_stock';
    const TYPE_OUT_OF_STOCK = 'out_of_stock';
    const TYPE_BACK_IN_STOCK = 'back_in_stock';
    const TYPE_STOCK_ADDED = 'stock_added';
    const TYPE_STOCK_REPLENISHED = 'stock_replenished';
    const TYPE_PRODUCT_REMOVED = 'product_removed';
    const TYPE_BULK_IMPORT = 'bulk_import';
    const TYPE_MIN_STOCK_CHANGED = 'min_stock_changed';

    const TYPES = [
        self::TYPE_LOW_STOCK,
        self::TYPE_OUT_OF_STOCK,
        self::TYPE_BACK_IN_STOCK,
        self::TYPE_STOCK_ADDED,
        self::TYPE_STOCK_REPLENISHED,
        self::TYPE_PRODUCT_REMOVED,
        self::TYPE_BULK_IMPORT,
        self::TYPE_MIN_STOCK_CHANGED,
    ];

    /**
     * The types that describe a row's state rather than an event.
     */
    const LEVEL_TYPES = [
        self::TYPE_LOW_STOCK,
        self::TYPE_OUT_OF_STOCK,
    ];

    const SEVERITY_LEVELS = ['critical', 'warning', 'info'];

    const SEVERITIES = [
        self::TYPE_OUT_OF_STOCK      => 'critical',
        self::TYPE_LOW_STOCK         => 'warning',
        self::TYPE_BACK_IN_STOCK     => 'info',
        self::TYPE_STOCK_ADDED       => 'info',
        self::TYPE_STOCK_REPLENISHED => 'info',
        self::TYPE_PRODUCT_REMOVED   => 'info',
        self::TYPE_BULK_IMPORT       => 'info',
        self::TYPE_MIN_STOCK_CHANGED => 'info',
    ];

    protected $fillable = [
        'type',
        'severity',
        'store_id',
        'store_name',
        'product_id',
        'product_name',
        'inventory_id',
        'quantity',
        'left_quantity',
        'min_stock',
        'source',
        'reference',
        'title',
        'message',
        'meta',
        'triggered_by',
        'read_at',
        'read_by',
        'resolved_at',
    ];

    protected $casts = [
        'store_id'      => 'integer',
        'product_id'    => 'integer',
        'inventory_id'  => 'integer',
        'quantity'      => 'decimal:2',
        'left_quantity' => 'decimal:2',
        'min_stock'     => 'decimal:2',
        'meta'          => 'array',
        'read_at'       => 'datetime',
        'resolved_at'   => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function readBy()
    {
        return $this->belongsTo(User::class, 'read_by');
    }

    /**
     * Level alerts that still describe a row's current state.
     */
    public function scopeOpenLevel($query)
    {
        return $query->whereIn('type', self::LEVEL_TYPES)->whereNull('resolved_at');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * @return bool
     */
    public function isLevel()
    {
        return in_array($this->type, self::LEVEL_TYPES, true);
    }
}
