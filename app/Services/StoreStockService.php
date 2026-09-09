<?php

namespace App\Services;

use App\Models\InventoryLog;
use App\Models\StoreProduct;
use App\Services\Concerns\SendsLowStockAlert;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The outside-store counterpart of InventoryStockService.
 *
 * Same contract, same 422 envelope, same movement log — only the stock row and
 * the floor differ. Own stock is found by product and floored at
 * products.min_stock; store stock is found by store_products row and floored at
 * that row's own threshold. The two numbers are unrelated and must not be read
 * across: this service never looks at min_stock.
 */
class StoreStockService
{
    use SendsLowStockAlert;

    /**
     * Issue stock from an outside store.
     *
     * @param  int          $storeProductId
     * @param  float        $quantity
     * @param  int          $userId
     * @param  int|null     $expectedStoreId  The store the service record is
     *                                        pinned to. When given, a part from
     *                                        any other store is rejected — one
     *                                        record draws from one store.
     * @param  string|null  $remarks
     * @return array
     */
    public function deductStock($storeProductId, $quantity, $userId, $expectedStoreId = null, $remarks = null)
    {
        $storeProduct = $this->findStoreProduct($storeProductId);

        if ($expectedStoreId !== null && (int) $storeProduct->store_id !== (int) $expectedStoreId) {
            $partName = $this->partName($storeProduct);
            $storeName = $this->storeName($storeProduct);

            throw new HttpResponseException(response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'spare_parts' => ["Spare part '{$partName}' belongs to '{$storeName}', which is not the store selected on this service record. A service record can only draw parts from one store."]
                ]
            ], 422));
        }

        $availableStock = (float) $storeProduct->left_quantity;
        $threshold = (float) $storeProduct->threshold;
        $qtyToDeduct = (float) $quantity;
        $partName = $this->partName($storeProduct);
        $storeName = $this->storeName($storeProduct);

        // Hard floor, exactly as min_stock is for the mine's own inventory.
        if (($availableStock - $qtyToDeduct) < $threshold) {
            $this->sendLowStockAlert($partName, $availableStock, $storeName);

            throw new HttpResponseException(response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'spare_parts' => ["Cannot issue stock for '{$partName}' from '{$storeName}'. Stock level after deduction would drop below the store's minimum quantity ({$threshold}). Current available stock: {$availableStock}."]
                ]
            ], 422));
        }

        // Only left_quantity moves; quantity is the running total ever stocked.
        $storeProduct->left_quantity -= $qtyToDeduct;
        $storeProduct->save();

        if ((float) $storeProduct->left_quantity <= $threshold) {
            $this->sendLowStockAlert($partName, (float) $storeProduct->left_quantity, $storeName);
        }

        InventoryLog::create([
            'product_id' => $storeProduct->product_id,
            'store_id'   => $storeProduct->store_id,
            'user_id'    => $userId,
            'type'       => 'out',
            'action'     => 'service_spare_part',
            'quantity'   => -$qtyToDeduct,
            'remarks'    => "Issued {$qtyToDeduct} units from store '{$storeName}' for service record" . ($remarks ? " - {$remarks}" : "")
        ]);

        return [
            'part_name'  => $partName,
            'product_id' => (int) $storeProduct->product_id,
            'store_id'   => (int) $storeProduct->store_id,
            'store_name' => $storeName,
        ];
    }

    /**
     * Return previously issued store stock.
     *
     * The exact inverse of deductStock, and deliberately without a threshold
     * check: putting stock back can never make a store's position worse.
     *
     * @param  int          $storeProductId
     * @param  float        $quantity
     * @param  int          $userId
     * @param  string|null  $remarks
     * @return array
     */
    public function restockStock($storeProductId, $quantity, $userId, $remarks = null)
    {
        $storeProduct = $this->findStoreProduct($storeProductId);

        $qtyToReturn = (float) $quantity;
        $storeName = $this->storeName($storeProduct);

        $storeProduct->left_quantity += $qtyToReturn;
        $storeProduct->save();

        InventoryLog::create([
            'product_id' => $storeProduct->product_id,
            'store_id'   => $storeProduct->store_id,
            'user_id'    => $userId,
            'type'       => 'in',
            'action'     => 'service_spare_part_return',
            'quantity'   => $qtyToReturn,
            'remarks'    => "Returned {$qtyToReturn} units to store '{$storeName}' from service record" . ($remarks ? " - {$remarks}" : "")
        ]);

        return [
            'part_name'  => $this->partName($storeProduct),
            'product_id' => (int) $storeProduct->product_id,
            'store_id'   => (int) $storeProduct->store_id,
            'store_name' => $storeName,
        ];
    }

    /**
     * @param  int  $storeProductId
     * @return StoreProduct
     */
    protected function findStoreProduct($storeProductId)
    {
        $storeProduct = StoreProduct::with(['store', 'product'])->find($storeProductId);

        if (!$storeProduct) {
            throw new HttpResponseException(response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => [
                    'spare_parts' => ["Store stock record with ID {$storeProductId} not found."]
                ]
            ], 422));
        }

        return $storeProduct;
    }

    /**
     * @param  StoreProduct  $storeProduct
     * @return string
     */
    protected function partName(StoreProduct $storeProduct)
    {
        return optional($storeProduct->product)->name ?: 'Unknown Product';
    }

    /**
     * @param  StoreProduct  $storeProduct
     * @return string
     */
    protected function storeName(StoreProduct $storeProduct)
    {
        return optional($storeProduct->store)->name ?: 'Unknown Store';
    }
}
