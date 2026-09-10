<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Inventory;
use App\Models\InventoryLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Bulk-add stock to one store.
 *
 * The sheet carries product name and quantity only — the store is chosen once
 * on the upload and applies to every row, so the same sheet can be uploaded
 * again for a second store.
 */
class InventoryImport implements ToCollection, WithHeadingRow
{
    protected $successCount = 0;
    protected $errors = [];
    protected $warnings = [];

    /**
     * @var int
     */
    protected $storeId;

    /**
     * @param  int  $storeId
     */
    public function __construct($storeId)
    {
        $this->storeId = (int) $storeId;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowArray = $row->toArray();
            $keys = array_keys($rowArray);

            $productName = isset($rowArray['product_name']) ? trim((string) $rowArray['product_name']) : '';
            if ($productName === '') {
                foreach ($keys as $k) {
                    if (stripos((string) $k, 'name') !== false || stripos((string) $k, 'product') !== false) {
                        $productName = trim((string) ($rowArray[$k] ?? ''));
                        break;
                    }
                }
            }

            $quantity = isset($rowArray['quantity']) ? trim((string) $rowArray['quantity']) : '';
            if ($quantity === '') {
                foreach ($keys as $k) {
                    if (stripos((string) $k, 'qty') !== false || stripos((string) $k, 'quantity') !== false || stripos((string) $k, 'stock') !== false) {
                        $quantity = trim((string) ($rowArray[$k] ?? ''));
                        break;
                    }
                }
            }

            $rowNum = $index + 2; // 1-indexed, +2 because of heading row

            if ($productName === '' && $quantity === '') {
                continue; // Skip empty rows
            }

            if ($productName === '') {
                $this->errors[] = [
                    'row' => $rowNum,
                    'column' => 'product_name',
                    'message' => 'Product name is empty.',
                    'value' => ''
                ];
                continue;
            }

            if ($quantity === '' || !is_numeric($quantity) || (float) $quantity <= 0) {
                $this->errors[] = [
                    'row' => $rowNum,
                    'column' => 'quantity',
                    'message' => 'Quantity must be a valid number greater than 0.',
                    'value' => $quantity
                ];
                continue;
            }

            $quantity = (float) $quantity;

            // Find product by name
            $product = Product::where('name', $productName)->first();

            if (!$product) {
                $this->errors[] = [
                    'row' => $rowNum,
                    'column' => 'product_name',
                    'message' => "Product '{$productName}' not found.",
                    'value' => $productName
                ];
                continue;
            }

            // Min stock validation
            if ($quantity < (float) $product->min_stock) {
                $this->errors[] = [
                    'row' => $rowNum,
                    'column' => 'quantity',
                    'message' => "The quantity ({$quantity}) must be at least {$product->min_stock} (minimum stock level for '{$productName}').",
                    'value' => $quantity
                ];
                continue;
            }

            // Check if product is already stocked at this store. The same
            // product at another store is a different row and no conflict.
            $inventoryExists = Inventory::where('store_id', $this->storeId)
                ->where('product_id', $product->id)
                ->exists();
            if ($inventoryExists) {
                $this->errors[] = [
                    'row' => $rowNum,
                    'column' => 'product_name',
                    'message' => "Product '{$productName}' is already added to this store's inventory.",
                    'value' => $productName
                ];
                continue;
            }

            // Save to database
            $storeId = $this->storeId;
            DB::transaction(function () use ($product, $quantity, $storeId) {
                $inventory = Inventory::create([
                    'store_id' => $storeId,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'left_quantity' => $quantity,
                    'is_active' => 1
                ]);

                // Create log entry
                InventoryLog::create([
                    'product_id' => $product->id,
                    'store_id' => $storeId,
                    'user_id' => auth()->id(),
                    'type' => 'in',
                    'action' => 'added',
                    'quantity' => $quantity,
                    'remarks' => 'Bulk uploaded via excel file'
                ]);
            });

            $this->successCount++;
        }
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
