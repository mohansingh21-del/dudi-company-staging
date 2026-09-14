<?php

namespace App\Http\Requests\Traits;

use App\Models\Inventory;
use Illuminate\Contracts\Validation\Validator;

/**
 * A service record that uses spare parts draws them from exactly one store.
 *
 * The store lives on the record rather than on each part row, so this can't be
 * expressed as a per-field rule in rules() — it depends on which stock rows the
 * spare_parts array points at.
 *
 * Checking it here rather than in the stock service means a record mixing two
 * stores is rejected as a validation error, before any stock has moved, instead
 * of part-way through the transaction.
 *
 * Shared by the store and update requests so the two cannot drift.
 */
trait ValidatesSpareParts
{
    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $validator) {
            $this->reportMissingProducts($validator);
            $this->reportUnresolvedStoreProducts($validator);
            $this->reportRepeatedStockRows($validator);

            // Nothing to resolve against if the ids themselves failed to
            // validate — those errors are the useful ones.
            if ($validator->errors()->hasAny(['spare_parts', 'store_id'])) {
                return;
            }

            $parts = $this->input('spare_parts');

            if (!is_array($parts) || empty($parts)) {
                return;
            }

            $inventoryIds = [];
            foreach ($parts as $part) {
                if (is_array($part) && !empty($part['inventory_id'])) {
                    $inventoryIds[] = (int) $part['inventory_id'];
                }
            }

            if (empty($inventoryIds)) {
                return;
            }

            $storeIds = Inventory::whereIn('id', array_unique($inventoryIds))
                ->distinct()
                ->pluck('store_id')
                ->map(function ($storeId) {
                    return (int) $storeId;
                })
                ->unique()
                ->values();

            if ($storeIds->isEmpty()) {
                return;
            }

            if ($storeIds->count() > 1) {
                $validator->errors()->add(
                    'spare_parts',
                    'All spare parts on a service record must come from the same store.'
                );

                return;
            }

            // The stored store stands in on an update that changes only the
            // parts, the same way normalisation resolves them against it.
            $recordStoreId = $this->serviceRecordStoreId();

            if ($recordStoreId === null) {
                $validator->errors()->add(
                    'store_id',
                    'Store is required when a service record has spare parts.'
                );

                return;
            }

            if ($recordStoreId !== $storeIds->first()) {
                $validator->errors()->add(
                    'store_id',
                    'The selected store does not match the store the spare parts are stocked at.'
                );
            }
        });
    }

    /**
     * Report a line that names no product.
     *
     * A posted inventory_id is discarded during normalisation, so the product
     * id is the only thing that can name a line's stock row. Without it the
     * line would reach the stock service pointing at nothing — a 500 from the
     * foreign key on update — so it is rejected here instead.
     *
     * @param  Validator  $validator
     * @return void
     */
    protected function reportMissingProducts(Validator $validator)
    {
        $parts = $this->input('spare_parts');

        if (!is_array($parts)) {
            return;
        }

        foreach ($parts as $index => $part) {
            if (is_array($part) && (!empty($part['store_product_id']) || !empty($part['product_id']))) {
                continue;
            }

            $validator->errors()->add(
                "spare_parts.{$index}.store_product_id",
                'The spare_parts.' . $index . '.store_product_id field is required when spare parts is present.'
            );
        }
    }

    /**
     * Report a line naming a product the record's store does not stock.
     *
     * Normalisation deliberately resolves nothing for these, so without this
     * the line would keep whatever inventory_id the client posted — which is
     * how a part ends up saved against another part's stock.
     *
     * @param  Validator  $validator
     * @return void
     */
    protected function reportUnresolvedStoreProducts(Validator $validator)
    {
        foreach ($this->unresolvedStoreProducts() as $index => $productId) {
            $validator->errors()->add(
                "spare_parts.{$index}.store_product_id",
                "Product {$productId} is not stocked at the selected store."
            );
        }
    }

    /**
     * Report two lines drawing on the same stock row.
     *
     * Two lines against one row is never what the form meant — it is a part
     * picked twice, or ids that did not vary with the products they came from.
     * Saved, it reads back as the same part twice and takes both deductions out
     * of that one balance, so it is rejected rather than merged: the quantities
     * and amounts to keep are the user's call, not this method's.
     *
     * @param  Validator  $validator
     * @return void
     */
    protected function reportRepeatedStockRows(Validator $validator)
    {
        $parts = $this->input('spare_parts');

        if (!is_array($parts)) {
            return;
        }

        $seen = [];

        foreach ($parts as $index => $part) {
            if (!is_array($part) || empty($part['inventory_id'])) {
                continue;
            }

            $inventoryId = (int) $part['inventory_id'];

            if (isset($seen[$inventoryId])) {
                $first = $seen[$inventoryId] + 1;

                $validator->errors()->add(
                    "spare_parts.{$index}.inventory_id",
                    "This is the same part as line {$first}. Put it on one line with the total quantity instead."
                );

                continue;
            }

            $seen[$inventoryId] = $index;
        }
    }
}
