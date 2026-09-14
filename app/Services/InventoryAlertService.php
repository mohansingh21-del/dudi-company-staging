<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryAlert;
use App\Models\Product;
use App\Services\Concerns\SendsLowStockAlert;
use Illuminate\Support\Collection;

/**
 * Writes the inventory alert feed.
 *
 * products.min_stock is a warning line, not a floor: crossing it raises an
 * alert here but never stops an issue. Only an empty shelf does that, and that
 * check lives with the deduction, not in this class.
 *
 * Alerts are written inside the caller's transaction, so a movement that rolls
 * back takes its alerts with it.
 */
class InventoryAlertService
{
    use SendsLowStockAlert;

    /**
     * Bring a row's open level alerts in line with its current left_quantity.
     *
     * Call it after any movement, in either direction. It is idempotent: an
     * alert is raised only when the row enters a state it has no open alert
     * for, so issuing again from a row that is already low raises nothing new.
     * The low-stock mail goes out on exactly those transitions.
     *
     * @param  Inventory    $inventory
     * @param  string       $source
     * @param  int|null     $userId
     * @param  string|null  $reference
     * @return void
     */
    public function syncStockLevel(Inventory $inventory, $source, $userId = null, $reference = null)
    {
        $inventory->loadMissing(['product', 'store']);

        $left = (float) $inventory->left_quantity;
        $minStock = (float) optional($inventory->product)->min_stock;

        $open = InventoryAlert::openLevel()
            ->where('inventory_id', $inventory->id)
            ->get()
            ->keyBy('type');

        $productName = $this->productName($inventory);
        $storeName = $this->storeName($inventory);

        if ($left <= 0) {
            // Out of stock supersedes low stock.
            $this->resolve($open, [InventoryAlert::TYPE_LOW_STOCK]);

            if (!$open->has(InventoryAlert::TYPE_OUT_OF_STOCK)) {
                $this->raise(InventoryAlert::TYPE_OUT_OF_STOCK, $this->rowAttributes($inventory, $source, $userId, $reference) + [
                    'title'   => "Out of stock: {$productName}",
                    'message' => "{$productName} is out of stock at {$storeName}. It cannot be issued until it is restocked.",
                ]);

                $this->sendLowStockAlert($productName, $left, $storeName, true);
            }

            return;
        }

        if ($left <= $minStock) {
            // Partly restocked from empty: still low, no longer out.
            $this->resolve($open, [InventoryAlert::TYPE_OUT_OF_STOCK]);

            if (!$open->has(InventoryAlert::TYPE_LOW_STOCK)) {
                $this->raise(InventoryAlert::TYPE_LOW_STOCK, $this->rowAttributes($inventory, $source, $userId, $reference) + [
                    'title'   => "Low stock: {$productName}",
                    'message' => "{$productName} at {$storeName} has " . $this->number($left) . " left, at or below its minimum stock of " . $this->number($minStock) . ".",
                ]);

                $this->sendLowStockAlert($productName, $left, $storeName);
            }

            return;
        }

        if ($open->isNotEmpty()) {
            $this->resolve($open, InventoryAlert::LEVEL_TYPES);

            $this->raise(InventoryAlert::TYPE_BACK_IN_STOCK, $this->rowAttributes($inventory, $source, $userId, $reference) + [
                'title'   => "Back in stock: {$productName}",
                'message' => "{$productName} at {$storeName} is back to " . $this->number($left) . ", above its minimum stock of " . $this->number($minStock) . ".",
            ]);
        }
    }

    /**
     * Stock put on the shelf by hand: a product stocked at a store for the
     * first time, or a top-up of one already there.
     *
     * @param  Inventory    $inventory
     * @param  float        $quantity
     * @param  bool         $isNew
     * @param  string       $source
     * @param  int|null     $userId
     * @param  string|null  $reference
     * @return void
     */
    public function stockAdded(Inventory $inventory, $quantity, $isNew, $source, $userId = null, $reference = null)
    {
        $inventory->loadMissing(['product', 'store']);

        $productName = $this->productName($inventory);
        $storeName = $this->storeName($inventory);
        $qty = $this->number($quantity);

        if ($isNew) {
            $this->raise(InventoryAlert::TYPE_STOCK_ADDED, $this->rowAttributes($inventory, $source, $userId, $reference, $quantity) + [
                'title'   => "Product added: {$productName}",
                'message' => "{$productName} was added to {$storeName} with {$qty} units.",
            ]);
        } else {
            $this->raise(InventoryAlert::TYPE_STOCK_REPLENISHED, $this->rowAttributes($inventory, $source, $userId, $reference, $quantity) + [
                'title'   => "Stock replenished: {$productName}",
                'message' => "{$qty} units of {$productName} were added at {$storeName}. " . $this->number($inventory->left_quantity) . " now left.",
            ]);
        }

        $this->syncStockLevel($inventory, $source, $userId, $reference);
    }

    /**
     * A product taken off a store. Its level alerts can never recover now, so
     * they are closed along with it.
     *
     * @param  Inventory  $inventory
     * @param  int|null   $userId
     * @return void
     */
    public function productRemoved(Inventory $inventory, $userId = null)
    {
        $inventory->loadMissing(['product', 'store']);

        InventoryAlert::openLevel()
            ->where('inventory_id', $inventory->id)
            ->update(['resolved_at' => now()]);

        $productName = $this->productName($inventory);

        $this->raise(InventoryAlert::TYPE_PRODUCT_REMOVED, $this->rowAttributes($inventory, 'store_unmap', $userId, null) + [
            'title'   => "Product removed: {$productName}",
            'message' => "{$productName} was removed from {$this->storeName($inventory)}.",
        ]);
    }

    /**
     * One summary for a whole upload — a row per imported product would bury
     * everything else in the feed. The level alerts for each row are still
     * raised individually by the import.
     *
     * @param  int       $successCount
     * @param  int       $storeCount
     * @param  int       $errorCount
     * @param  int|null  $userId
     * @return void
     */
    public function bulkImported($successCount, $storeCount, $errorCount, $userId = null)
    {
        $message = "{$successCount} products were imported into inventory across {$storeCount} store(s).";

        if ($errorCount > 0) {
            $message .= " {$errorCount} row(s) were rejected.";
        }

        $this->raise(InventoryAlert::TYPE_BULK_IMPORT, [
            'source'       => 'bulk_import',
            'triggered_by' => $userId,
            'title'        => 'Bulk import completed',
            'message'      => $message,
            'meta'         => [
                'imported' => (int) $successCount,
                'stores'   => (int) $storeCount,
                'rejected' => (int) $errorCount,
            ],
        ]);
    }

    /**
     * A product's minimum stock moved, which can put rows under the line or
     * lift them over it without a single unit moving. Every store carrying the
     * product is re-checked.
     *
     * @param  Product   $product
     * @param  int       $oldMinStock
     * @param  int       $newMinStock
     * @param  int|null  $userId
     * @return void
     */
    public function minStockChanged(Product $product, $oldMinStock, $newMinStock, $userId = null)
    {
        $this->raise(InventoryAlert::TYPE_MIN_STOCK_CHANGED, [
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'min_stock'    => $newMinStock,
            'source'       => 'product_update',
            'triggered_by' => $userId,
            'title'        => "Minimum stock changed: {$product->name}",
            'message'      => "Minimum stock for {$product->name} changed from {$this->number($oldMinStock)} to {$this->number($newMinStock)}.",
            'meta'         => [
                'old_min_stock' => (int) $oldMinStock,
                'new_min_stock' => (int) $newMinStock,
            ],
        ]);

        $inventories = Inventory::with('store')->where('product_id', $product->id)->get();

        foreach ($inventories as $inventory) {
            $inventory->setRelation('product', $product);
            $this->syncStockLevel($inventory, 'product_update', $userId);
        }
    }

    /**
     * @param  string  $type
     * @param  array   $attributes
     * @return InventoryAlert
     */
    protected function raise($type, array $attributes)
    {
        return InventoryAlert::create(array_merge([
            'type'     => $type,
            'severity' => InventoryAlert::SEVERITIES[$type],
        ], $attributes));
    }

    /**
     * @param  Collection  $open   Open level alerts keyed by type.
     * @param  array       $types
     * @return void
     */
    protected function resolve(Collection $open, array $types)
    {
        foreach ($types as $type) {
            if ($open->has($type)) {
                $open->get($type)->update(['resolved_at' => now()]);
            }
        }
    }

    /**
     * The row-level columns every stock alert carries, snapshotted as of now.
     *
     * @return array
     */
    protected function rowAttributes(Inventory $inventory, $source, $userId, $reference, $quantity = null)
    {
        return [
            'store_id'      => $inventory->store_id,
            'store_name'    => optional($inventory->store)->name,
            'product_id'    => $inventory->product_id,
            'product_name'  => optional($inventory->product)->name,
            'inventory_id'  => $inventory->id,
            'quantity'      => $quantity,
            'left_quantity' => (float) $inventory->left_quantity,
            'min_stock'     => (float) optional($inventory->product)->min_stock,
            'source'        => $source,
            'reference'     => $reference,
            'triggered_by'  => $userId,
        ];
    }

    protected function productName(Inventory $inventory)
    {
        return optional($inventory->product)->name ?: 'Unknown Product';
    }

    protected function storeName(Inventory $inventory)
    {
        return optional($inventory->store)->name ?: 'Unknown Store';
    }

    /**
     * "12" rather than "12.00" in a sentence; fractions are kept.
     *
     * @param  float|int|string  $value
     * @return string
     */
    protected function number($value)
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
