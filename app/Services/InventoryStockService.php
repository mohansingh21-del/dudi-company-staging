<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryLog;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The one stock service.
 *
 * Every movement is against a single `inventories` row, which already names
 * both the product and the store. A deduction may take a row down to zero but
 * never below it. products.min_stock is only a warning line: crossing it raises
 * an inventory alert, it does not block the issue.
 */
class InventoryStockService
{
    /**
     * The rejection shared by every path that issues stock.
     *
     * @param  string       $productName
     * @param  string|null  $storeName
     * @param  float        $availableStock
     * @return string
     */
    public static function insufficientStockMessage($productName, $storeName, $availableStock)
    {
        $storeName = $storeName ?: 'Unknown Store';

        if ((float) $availableStock <= 0) {
            return "'{$productName}' is out of stock at '{$storeName}'. Add stock before issuing it.";
        }

        $available = rtrim(rtrim(number_format((float) $availableStock, 2, '.', ''), '0'), '.');

        return "Only {$available} units of '{$productName}' are left at '{$storeName}'.";
    }

    /**
     * Issue stock from a store.
     *
     * @param  int          $inventoryId
     * @param  float        $quantity
     * @param  int          $userId
     * @param  int|null     $expectedStoreId  The store the service record is
     *                                        pinned to. When given, a part from
     *                                        any other store is rejected — one
     *                                        record draws from one store.
     * @param  string|null  $remarks
     * @return array
     */
    public function deductStock($inventoryId, $quantity, $userId, $expectedStoreId = null, $remarks = null)
    {
        $inventory = $this->findInventory($inventoryId);

        $partName = $this->partName($inventory);
        $storeName = $this->storeName($inventory);

        if ($expectedStoreId !== null && (int) $inventory->store_id !== (int) $expectedStoreId) {
            throw new HttpResponseException(response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'spare_parts' => ["Spare part '{$partName}' belongs to '{$storeName}', which is not the store selected on this service record. A service record can only draw parts from one store."]
                ]
            ], 422));
        }

        $availableStock = (float) $inventory->left_quantity;
        $qtyToDeduct = (float) $quantity;

        // Only an empty shelf stops an issue. Dropping under min_stock is
        // allowed and raises an alert below.
        if ($qtyToDeduct > $availableStock) {
            throw new HttpResponseException(response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'spare_parts' => [self::insufficientStockMessage($partName, $storeName, $availableStock)]
                ]
            ], 422));
        }

        // Only left_quantity moves; quantity is the running total ever stocked.
        $inventory->left_quantity -= $qtyToDeduct;
        $inventory->save();

        InventoryLog::create([
            'product_id' => $inventory->product_id,
            'store_id'   => $inventory->store_id,
            'user_id'    => $userId,
            'type'       => 'out',
            'action'     => 'service_spare_part',
            'quantity'   => -$qtyToDeduct,
            'remarks'    => "Issued {$qtyToDeduct} units from store '{$storeName}' for service record" . ($remarks ? " - {$remarks}" : "")
        ]);

        $this->alerts()->syncStockLevel($inventory, 'service_record', $userId, $remarks);

        return [
            'part_name'  => $partName,
            'product_id' => (int) $inventory->product_id,
            'store_id'   => (int) $inventory->store_id,
            'store_name' => $storeName,
        ];
    }

    /**
     * Return previously issued stock.
     *
     * The exact inverse of deductStock, and deliberately without a floor check:
     * putting stock back can never make a store's position worse.
     *
     * @param  int          $inventoryId
     * @param  float        $quantity
     * @param  int          $userId
     * @param  string|null  $remarks
     * @return array
     */
    public function restockStock($inventoryId, $quantity, $userId, $remarks = null)
    {
        $inventory = $this->findInventory($inventoryId);

        $qtyToReturn = (float) $quantity;
        $storeName = $this->storeName($inventory);

        $inventory->left_quantity += $qtyToReturn;
        $inventory->save();

        InventoryLog::create([
            'product_id' => $inventory->product_id,
            'store_id'   => $inventory->store_id,
            'user_id'    => $userId,
            'type'       => 'in',
            'action'     => 'service_spare_part_return',
            'quantity'   => $qtyToReturn,
            'remarks'    => "Returned {$qtyToReturn} units to store '{$storeName}' from service record" . ($remarks ? " - {$remarks}" : "")
        ]);

        // A return can lift a row back over min_stock and close its alerts.
        $this->alerts()->syncStockLevel($inventory, 'service_record_return', $userId, $remarks);

        return [
            'part_name'  => $this->partName($inventory),
            'product_id' => (int) $inventory->product_id,
            'store_id'   => (int) $inventory->store_id,
            'store_name' => $storeName,
        ];
    }

    /**
     * @return InventoryAlertService
     */
    protected function alerts()
    {
        return app(InventoryAlertService::class);
    }

    /**
     * @param  int  $inventoryId
     * @return Inventory
     */
    protected function findInventory($inventoryId)
    {
        $inventory = Inventory::with(['store', 'product'])->find($inventoryId);

        if (!$inventory) {
            throw new HttpResponseException(response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'spare_parts' => ["Stock record with ID {$inventoryId} not found."]
                ]
            ], 422));
        }

        return $inventory;
    }

    /**
     * @param  Inventory  $inventory
     * @return string
     */
    protected function partName(Inventory $inventory)
    {
        return optional($inventory->product)->name ?: 'Unknown Product';
    }

    /**
     * @param  Inventory  $inventory
     * @return string
     */
    protected function storeName(Inventory $inventory)
    {
        return optional($inventory->store)->name ?: 'Unknown Store';
    }
}
