<?php

namespace App\Services;

use App\Models\Inventory;

/**
 * Stock figures for the dashboard.
 *
 * Stock is a live snapshot of the inventories table, not a dated history, so
 * none of the dashboard's date, site, shift or machine filters apply here —
 * stores are not tied to a mine site. The only filter is store_id.
 *
 * The two lists use the same non-overlapping states as
 * Inventory::stockStatus() and the inventory list's stock_status filter:
 *   below_min_level — some left, but at or under products.min_stock
 *   out_of_stock    — nothing left on the shelf (zero or below)
 */
class InventoryDashboardService
{
    /**
     * Counts for the three stock cards. Returned with both lists, so whichever
     * table the page loads first also fills the cards.
     *
     * @param int|null $storeId
     * @return array
     */
    public function summary($storeId = null): array
    {
        return [
            'total_products'  => $this->baseQuery($storeId)->count(),
            'below_min_level' => $this->belowMinLevelQuery($storeId)->count(),
            'out_of_stock'    => $this->outOfStockQuery($storeId)->count(),
        ];
    }

    /**
     * @param int|null $storeId
     * @param int $perPage
     * @param int|null $page
     * @return array
     */
    public function getBelowMinLevel($storeId = null, int $perPage = 10, $page = null): array
    {
        $query = $this->belowMinLevelQuery($storeId)
            ->orderBy('inventories.left_quantity')
            ->orderBy('products.name');

        return $this->paginate($query, $perPage, $page);
    }

    /**
     * @param int|null $storeId
     * @param int $perPage
     * @param int|null $page
     * @return array
     */
    public function getOutOfStock($storeId = null, int $perPage = 10, $page = null): array
    {
        $query = $this->outOfStockQuery($storeId)
            ->orderBy('products.name')
            ->orderBy('stores.name');

        return $this->paginate($query, $perPage, $page);
    }

    protected function belowMinLevelQuery($storeId)
    {
        return $this->baseQuery($storeId)
            ->where('inventories.left_quantity', '>', 0)
            ->whereColumn('inventories.left_quantity', '<=', 'products.min_stock');
    }

    protected function outOfStockQuery($storeId)
    {
        return $this->baseQuery($storeId)
            ->where('inventories.left_quantity', '<=', 0);
    }

    protected function baseQuery($storeId)
    {
        return Inventory::query()
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->join('stores', 'stores.id', '=', 'inventories.store_id')
            ->when($storeId, function ($q) use ($storeId) {
                return $q->where('inventories.store_id', $storeId);
            });
    }

    protected function paginate($query, int $perPage, $page): array
    {
        $paginator = $query
            ->select([
                'inventories.id',
                'inventories.product_id',
                'inventories.store_id',
                'inventories.left_quantity',
                'products.name as product_name',
                'products.min_stock',
                'stores.name as store_name',
            ])
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function ($item) {
            $status = Inventory::stockStatus($item->left_quantity, $item->min_stock);

            return [
                'inventory_id'       => $item->id,
                'product_id'         => $item->product_id,
                'product'            => $item->product_name,
                'store_id'           => $item->store_id,
                'location'           => $item->store_name,
                'minimum_stock'      => (int) $item->min_stock,
                'quantity'           => round((float) $item->left_quantity, 2),
                'stock_status'       => $status,
                'stock_status_label' => Inventory::STOCK_STATUS_LABELS[$status],
            ];
        });

        return [
            'items'        => $items,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
        ];
    }
}
