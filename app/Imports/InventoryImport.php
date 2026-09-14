<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Store;
use App\Models\Inventory;
use App\Models\InventoryLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Bulk-add stock, one row per (store, product).
 *
 * The sheet may carry a store_name column, in which case a single file can
 * stock several stores at once — a row names the store it belongs to. Name
 * rather than id because whoever fills the sheet knows "Main Store" and has no
 * reason to know it is store 3; an id is still accepted in that column, and a
 * store_id column is read after it for sheets exported from the system.
 *
 * Leaving the column off falls back to the store chosen on the upload, which
 * applies to every row; sheets written before the column existed still import.
 */
class InventoryImport implements ToCollection, WithHeadingRow
{
    protected $successCount = 0;
    protected $errors = [];
    protected $warnings = [];

    /**
     * The store rows fall back to when the sheet does not name one. Null when
     * the upload left it off, which makes the column mandatory per row.
     *
     * @var int|null
     */
    protected $storeId;

    /**
     * Resolved stores, keyed by the raw cell value. A sheet stocking three
     * stores across four hundred rows should not be four hundred lookups.
     *
     * @var array<string, \App\Models\Store|null>
     */
    protected $storeCache = [];

    /**
     * Ids of the stores this file actually stocked, used as a set.
     *
     * @var array<int, bool>
     */
    protected $importedStoreIds = [];

    /**
     * @param  int|null  $storeId
     */
    public function __construct($storeId = null)
    {
        $this->storeId = $storeId === null || $storeId === '' ? null : (int) $storeId;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowArray = $row->toArray();
            $keys = array_keys($rowArray);

            $productName = isset($rowArray['product_name']) ? trim((string) $rowArray['product_name']) : '';
            if ($productName === '') {
                foreach ($keys as $k) {
                    // "store_name" matches on "name" but is not the product —
                    // skip anything store-ish before the loose match runs.
                    if (stripos((string) $k, 'store') !== false) {
                        continue;
                    }
                    if (stripos((string) $k, 'name') !== false || stripos((string) $k, 'product') !== false) {
                        $productName = trim((string) ($rowArray[$k] ?? ''));
                        break;
                    }
                }
            }

            // store_name is the column this sheet is written around: whoever
            // fills it knows the store by name, not by id. store_id is still
            // read after it for anyone exporting from the system, then any
            // other store-ish heading. A blank cell keeps the search going
            // rather than settling for the first column that matches, since a
            // sheet may well carry both columns with only one filled in.
            $storeColumn = 'store_name';
            $storeValue = '';
            foreach (['store_name', 'store_id'] as $k) {
                $candidate = isset($rowArray[$k]) ? trim((string) $rowArray[$k]) : '';
                if ($candidate !== '') {
                    $storeColumn = $k;
                    $storeValue = $candidate;
                    break;
                }
            }
            if ($storeValue === '') {
                foreach ($keys as $k) {
                    if (stripos((string) $k, 'store') === false) {
                        continue;
                    }
                    $candidate = trim((string) ($rowArray[$k] ?? ''));
                    if ($candidate !== '') {
                        $storeColumn = (string) $k;
                        $storeValue = $candidate;
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

            // Which store this row stocks. The sheet wins over the upload, so
            // one file can spread across stores; the upload covers the rows
            // that name none.
            if ($storeValue !== '') {
                $store = $this->resolveStore($storeValue);

                if (!$store) {
                    $this->errors[] = [
                        'row' => $rowNum,
                        // Whichever column actually supplied the value, so the
                        // UI highlights the cell the user typed in.
                        'column' => $storeColumn,
                        'message' => "Store '{$storeValue}' not found.",
                        'value' => $storeValue
                    ];
                    continue;
                }

                $storeId = (int) $store->id;
            } else {
                if ($this->storeId === null) {
                    $this->errors[] = [
                        'row' => $rowNum,
                        'column' => 'store_name',
                        'message' => 'Store is empty. Add a store_name column to the sheet or choose a store for the upload.',
                        'value' => ''
                    ];
                    continue;
                }

                $storeId = $this->storeId;
            }

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

            // Check if product is already stocked at this store. The same
            // product at another store is a different row and no conflict, so
            // a file may well repeat a product once per store.
            $inventoryExists = Inventory::where('store_id', $storeId)
                ->where('product_id', $product->id)
                ->exists();
            if ($inventoryExists) {
                $storeName = optional($this->storeById($storeId))->name ?? "store #{$storeId}";
                $this->errors[] = [
                    'row' => $rowNum,
                    'column' => 'product_name',
                    'message' => "Product '{$productName}' is already added to the inventory of '{$storeName}'.",
                    'value' => $productName
                ];
                continue;
            }

            // Save to database
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

                // Stock at or under min_stock is accepted but already low.
                app(\App\Services\InventoryAlertService::class)
                    ->syncStockLevel($inventory, 'bulk_import', auth()->id());
            });

            $this->importedStoreIds[$storeId] = true;
            $this->successCount++;
        }
    }

    /**
     * Resolve a store cell to a store. Numeric is an id, anything else is a
     * name — the sheet is filled by hand and names are what people know.
     *
     * @param  string  $value
     * @return \App\Models\Store|null
     */
    protected function resolveStore($value)
    {
        if (!array_key_exists($value, $this->storeCache)) {
            $this->storeCache[$value] = is_numeric($value)
                ? Store::find((int) $value)
                : Store::where('name', $value)->first();
        }

        return $this->storeCache[$value];
    }

    /**
     * The store behind an already-resolved id, for error messages.
     *
     * @param  int  $storeId
     * @return \App\Models\Store|null
     */
    protected function storeById($storeId)
    {
        return $this->resolveStore((string) $storeId);
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    /**
     * How many distinct stores the file actually stocked.
     */
    public function getStoreCount(): int
    {
        return count($this->importedStoreIds);
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
