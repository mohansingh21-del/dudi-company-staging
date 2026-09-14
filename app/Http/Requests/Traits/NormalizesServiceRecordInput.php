<?php

namespace App\Http\Requests\Traits;

use App\Models\Inventory;
use App\Models\ServiceRecord;

trait NormalizesServiceRecordInput
{
    /**
     * Top level boolean fields on a service record payload.
     *
     * @var array
     */
    protected $serviceRecordBooleans = [
        'is_breakdown_service',
        'spare_parts_changed',
    ];

    /**
     * Boolean fields nested inside the checklist object.
     *
     * @var array
     */
    protected $serviceChecklistBooleans = [
        'oil_change',
        'hydraulic_oil',
        'gear_oil',
        'fuel_filter_change',
        'oil_filter_change',
    ];

    /**
     * spare_parts indexes whose store_product_id names no stock row at this
     * record's store, as index => product id. Reported by ValidatesSpareParts.
     *
     * @var array
     */
    protected $unresolvedStoreProducts = [];

    /**
     * Cast "true"/"false"/"yes"/"on" strings to real booleans.
     *
     * multipart/form-data requests (needed for attachments) send every value as a
     * string, and Laravel's boolean rule only accepts true, false, 0, 1, '0', '1'.
     * Without this, a literal "false" both fails the boolean rule and loosely
     * matches required_if:...,true — making breakdown_id wrongly required.
     */
    protected function prepareForValidation()
    {
        $merge = [];

        foreach ($this->serviceRecordBooleans as $field) {
            if ($this->has($field)) {
                $merge[$field] = $this->toBoolean($this->input($field));
            }
        }

        $checklist = $this->input('checklist');

        if (is_array($checklist)) {
            foreach ($this->serviceChecklistBooleans as $field) {
                if (array_key_exists($field, $checklist)) {
                    $checklist[$field] = $this->toBoolean($checklist[$field]);
                }
            }

            $merge['checklist'] = $checklist;
        }

        $parts = $this->resolveSparePartStockRows();

        if ($parts !== null) {
            $merge['spare_parts'] = $parts;
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }

        // spare_parts only matters when spare_parts_changed is true. Clients
        // (multipart form-data especially) can leave a stray empty
        // spare_parts[] field behind even when nothing changed, which would
        // otherwise trip required_with:spare_parts on spare_parts.*.source
        // and spare_parts.*.quantity. Drop it so it's ignored everywhere.
        if (!$this->boolean('spare_parts_changed')) {
            $this->request->remove('spare_parts');
            $this->query->remove('spare_parts');
        }
    }

    /**
     * Point every spare part line at the stock row it actually came out of.
     *
     * Clients still post the pre-migration field name (store_product_id,
     * alongside a now-unused source flag), which carries the product's own id.
     * A product id does not name a stock row on its own — the same product is a
     * separate row, and a separate balance, at every store that carries it — so
     * it is resolved against this record's store.
     *
     * A posted inventory_id is discarded outright, for now, on both store and
     * update. The live form repeats one inventory_id across lines while
     * varying store_product_id, so trusting it saves every line against the
     * first line's part: the record shows the same part twice, and that part's
     * stock absorbs a deduction belonging to another. store_product_id is the
     * field such a payload varies per line, so it is the only one read — a line
     * without it is left with no stock row and fails validation.
     *
     * Nothing is guessed when a product is not stocked at the store — the index
     * is recorded for ValidatesSpareParts to report, rather than silently
     * falling back to an id that points somewhere else.
     *
     * @return array|null  The rewritten lines, or null when there are none.
     */
    protected function resolveSparePartStockRows()
    {
        $parts = $this->input('spare_parts');

        if (!is_array($parts)) {
            return null;
        }

        $storeId = $this->serviceRecordStoreId();

        foreach ($parts as $index => $part) {
            if (!is_array($part)) {
                continue;
            }

            $parts[$index]['inventory_id'] = null;

            if (empty($part['store_product_id'])) {
                continue;
            }

            $productId = (int) $part['store_product_id'];

            $resolved = $storeId
                ? Inventory::where('store_id', $storeId)
                    ->where('product_id', $productId)
                    ->value('id')
                : null;

            if (!$resolved) {
                // inventory_id stays null: validation fails on the error
                // added for this index, so nothing is guessed.
                $this->unresolvedStoreProducts[$index] = $productId;

                continue;
            }

            $parts[$index]['inventory_id'] = (int) $resolved;
        }

        return $parts;
    }

    /**
     * The store this record draws its parts from.
     *
     * An update that changes only the parts does not have to resend store_id,
     * so the stored one stands in — without it every line on such a request
     * would be unresolvable.
     *
     * @return int|null
     */
    protected function serviceRecordStoreId()
    {
        if ($this->filled('store_id')) {
            return (int) $this->input('store_id');
        }

        $record = $this->route('service_record');

        return $record instanceof ServiceRecord && $record->store_id
            ? (int) $record->store_id
            : null;
    }

    /**
     * @return array  index => product id
     */
    public function unresolvedStoreProducts()
    {
        return $this->unresolvedStoreProducts;
    }

    /**
     * Convert a single value to boolean, leaving unrecognised values untouched
     * so the boolean rule still reports them as invalid.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected function toBoolean($value)
    {
        if (is_bool($value) || is_null($value)) {
            return $value;
        }

        $converted = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_null($converted) ? $value : $converted;
    }
}
