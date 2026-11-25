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
 *
 * Product names are only unique within a sub-category, so a name alone can
 * point at several products. The sheet may narrow it with sub_category_name
 * and category_name columns (the headings the export writes are accepted too),
 * or name the product outright with product_id. A name that still matches more
 * than one product is a row error — guessing would stock the wrong product.
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
     * Resolved product lookups, keyed by the cells that identified them. Each
     * entry is the list of matching products, so an ambiguous name is only
     * queried once however many rows repeat it.
     *
     * @var array<string, \Illuminate\Support\Collection>
     */
    protected $productCache = [];

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
                    // Likewise category_name / sub_category_name, and
                    // product_id, which is read on its own below.
                    if (stripos((string) $k, 'store') !== false
                        || stripos((string) $k, 'category') !== false
                        || $k === 'product_id') {
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

            $productId = isset($rowArray['product_id']) ? trim((string) $rowArray['product_id']) : '';
            $subCategoryName = $this->firstFilled($rowArray, ['sub_category_name', 'sub_category', 'subcategory_name', 'subcategory']);
            $categoryName = $this->firstFilled($rowArray, ['category_name', 'category']);

            $rowNum = $index + 2; // 1-indexed, +2 because of heading row

            if ($productName === '' && $productId === '' && $quantity === '') {
                continue; // Skip empty rows
            }

            if ($productName === '' && $productId === '') {
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

            $matches = $this->resolveProducts($productId, $productName, $subCategoryName, $categoryName);

            if ($matches->isEmpty()) {
                $this->errors[] = [
                    'row' => $rowNum,
                    'column' => $productId !== '' ? 'product_id' : 'product_name',
                    'message' => $this->notFoundMessage($productId, $productName, $subCategoryName, $categoryName),
                    'value' => $productId !== '' ? $productId : $productName
                ];
                continue;
            }

            if ($matches->count() > 1) {
                $where = $matches->map(function ($p) {
                    return optional(optional($p->subCategory)->category)->name . ' / ' . optional($p->subCategory)->name;
                })->implode(', ');

                $this->errors[] = [
                    'row' => $rowNum,
                    'column' => 'sub_category_name',
                    'message' => "Product '{$productName}' exists in more than one sub-category ({$where}). Add sub_category_name (and category_name if needed) to choose one.",
                    'value' => $subCategoryName
                ];
                continue;
            }

            $product = $matches->first();

            // A product already stocked at this store is replenished, same as
            // adding stock by hand; otherwise the (store, product) row is
            // created. The same product at another store is a separate row, so
            // a file may well repeat a product once per store.
            DB::transaction(function () use ($product, $quantity, $storeId) {
                $inventory = Inventory::where('store_id', $storeId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                $isNew = !$inventory;

                if ($isNew) {
                    $inventory = Inventory::create([
                        'store_id' => $storeId,
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'left_quantity' => $quantity,
                        'is_active' => 1
                    ]);
                } else {
                    $inventory->quantity += $quantity;
                    $inventory->left_quantity += $quantity;
                    $inventory->save();
                }

                // Create log entry
                InventoryLog::create([
                    'product_id' => $product->id,
                    'store_id' => $storeId,
                    'user_id' => auth()->id(),
                    'type' => 'in',
                    'action' => 'added',
                    'quantity' => $quantity,
                    'remarks' => $isNew ? 'Bulk uploaded via excel file' : 'Replenished stock via excel file'
                ]);

                // Raises added/replenished, then re-checks low stock.
                app(\App\Services\InventoryAlertService::class)
                    ->stockAdded($inventory, $quantity, $isNew, 'bulk_import', auth()->id());
            });

            $this->importedStoreIds[$storeId] = true;
            $this->successCount++;
        }
    }

    /**
     * Every product the row's cells can point at. product_id wins when given;
     * the name, sub-category and category cells then only have to agree with
     * it. Otherwise the name is narrowed by whichever of the other two are
     * filled. The caller decides what none or several matches mean.
     *
     * @param  string  $productId
     * @param  string  $productName
     * @param  string  $subCategoryName
     * @param  string  $categoryName
     * @return \Illuminate\Support\Collection
     */
    protected function resolveProducts($productId, $productName, $subCategoryName, $categoryName)
    {
        $key = implode("\0", [$productId, $productName, $subCategoryName, $categoryName]);

        if (!array_key_exists($key, $this->productCache)) {
            $query = Product::with('subCategory.category');

            if ($productId !== '') {
                $query->where('id', is_numeric($productId) ? (int) $productId : 0);
            }

            if ($productName !== '') {
                $query->where('name', $productName);
            }

            if ($subCategoryName !== '') {
                $query->whereHas('subCategory', function ($q) use ($subCategoryName) {
                    $q->where('name', $subCategoryName);
                });
            }

            if ($categoryName !== '') {
                $query->whereHas('subCategory.category', function ($q) use ($categoryName) {
                    $q->where('name', $categoryName);
                });
            }

            $this->productCache[$key] = $query->get();
        }

        return $this->productCache[$key];
    }

    /**
     * @param  string  $productId
     * @param  string  $productName
     * @param  string  $subCategoryName
     * @param  string  $categoryName
     * @return string
     */
    protected function notFoundMessage($productId, $productName, $subCategoryName, $categoryName)
    {
        $label = $productId !== '' ? "Product id '{$productId}'" : "Product '{$productName}'";

        $scope = array_filter([
            $productId !== '' && $productName !== '' ? "name '{$productName}'" : '',
            $categoryName !== '' ? "category '{$categoryName}'" : '',
            $subCategoryName !== '' ? "sub-category '{$subCategoryName}'" : '',
        ]);

        return $scope
            ? "{$label} not found with " . implode(', ', $scope) . '.'
            : "{$label} not found.";
    }

    /**
     * The first non-blank cell among the given headings, or ''.
     *
     * @param  array  $row
     * @param  array  $keys
     * @return string
     */
    protected function firstFilled(array $row, array $keys)
    {
        foreach ($keys as $k) {
            $value = isset($row[$k]) ? trim((string) $row[$k]) : '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
