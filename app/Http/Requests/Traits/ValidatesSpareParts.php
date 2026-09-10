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

            if (!$this->filled('store_id')) {
                $validator->errors()->add(
                    'store_id',
                    'Store is required when a service record has spare parts.'
                );

                return;
            }

            if ((int) $this->input('store_id') !== $storeIds->first()) {
                $validator->errors()->add(
                    'store_id',
                    'The selected store does not match the store the spare parts are stocked at.'
                );
            }
        });
    }
}
