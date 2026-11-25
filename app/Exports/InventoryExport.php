<?php

namespace App\Exports;

use App\Models\Inventory;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * The inventory list, downloaded — the Export button on Inventory Management.
 *
 * Takes the same filters as the list (see Inventory::scopeListFilter) so the
 * file holds the rows the screen was showing, just without pagination.
 * One class serves both CSV and Excel; the controller picks the writer.
 */
class InventoryExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStrictNullComparison
{
    /**
     * @var int
     */
    protected $serial = 0;

    public function __construct(
        protected array $filters = []
    ) {}

    public function query()
    {
        return Inventory::query()
            ->with(['store', 'product.subCategory.category'])
            ->listFilter($this->filters)
            ->orderBy('inventories.id');
    }

    public function headings(): array
    {
        return [
            'Sr No',
            'Store',
            'Product',
            'Category',
            'Sub Category',
            'Available Stock',
            'Min Stock',
            'Stock Status',
            'Status',
            'Last Updated',
        ];
    }

    public function map($inventory): array
    {
        $product = $inventory->product;
        $leftQuantity = (float) $inventory->left_quantity;
        $minStock = (float) optional($product)->min_stock;
        $stockStatus = Inventory::stockStatus($leftQuantity, $minStock);

        return [
            ++$this->serial,
            optional($inventory->store)->name,
            optional($product)->name,
            optional(optional(optional($product)->subCategory)->category)->name,
            optional(optional($product)->subCategory)->name,
            $leftQuantity,
            $minStock,
            Inventory::STOCK_STATUS_LABELS[$stockStatus],
            $inventory->is_active ? 'Active' : 'Inactive',
            optional($inventory->updated_at)->format('d-m-Y H:i'),
        ];
    }
}
