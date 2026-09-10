<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Services\Concerns\SendsLowStockAlert;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The one stock service.
 *
 * Every movement is against a single `inventories` row, which already names
 * both the product and the store. The floor a deduction may not cross is
 * products.min_stock — the same number wherever the product is stocked.
 */
class InventoryStockService
{
    use SendsLowStockAlert;

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
        $minStock = (float) optional($inventory->product)->min_stock;
        $qtyToDeduct = (float) $quantity;

        if (($availableStock - $qtyToDeduct) < $minStock) {
            $this->sendLowStockAlert($partName, $availableStock, $storeName);

            throw new HttpResponseException(response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'spare_parts' => ["Cannot issue stock for '{$partName}' from '{$storeName}'. Stock level after deduction would drop below the minimum quantity ({$minStock}). Current available stock: {$availableStock}."]
                ]
            ], 422));
        }

        // Only left_quantity moves; quantity is the running total ever stocked.
        $inventory->left_quantity -= $qtyToDeduct;
        $inventory->save();

        if ((float) $inventory->left_quantity <= $minStock) {
            $this->sendLowStockAlert($partName, (float) $inventory->left_quantity, $storeName);
        }

        InventoryLog::create([
            'product_id' => $inventory->product_id,
            'store_id'   => $inventory->store_id,
            'user_id'    => $userId,
            'type'       => 'out',
            'action'     => 'service_spare_part',
            'quantity'   => -$qtyToDeduct,
            'remarks'    => "Issued {$qtyToDeduct} units from store '{$storeName}' for service record" . ($remarks ? " - {$remarks}" : "")
        ]);

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

        return [
            'part_name'  => $this->partName($inventory),
            'product_id' => (int) $inventory->product_id,
            'store_id'   => (int) $inventory->store_id,
            'store_name' => $storeName,
        ];
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
