<?php

namespace App\Http\Requests\Traits;

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

        // Older/other clients still post the pre-migration field name
        // (store_product_id, alongside a now-unused source flag) instead of
        // inventory_id. That value is actually the product's own id (a
        // holdover from when store_products was a product-keyed table), not
        // an inventories row id, so it has to be resolved against this
        // record's store rather than assumed to equal inventory_id directly
        // — the two only coincide by chance.
        $parts = $this->input('spare_parts');

        if (is_array($parts)) {
            $storeId = $this->input('store_id');

            foreach ($parts as $index => $part) {
                if (!is_array($part) || !empty($part['inventory_id']) || empty($part['store_product_id'])) {
                    continue;
                }

                $resolvedInventoryId = $storeId
                    ? \App\Models\Inventory::where('store_id', (int) $storeId)
                        ->where('product_id', (int) $part['store_product_id'])
                        ->value('id')
                    : null;

                // Falling back to the raw value when nothing resolves keeps the
                // exists:inventories,id rule as the one place that reports "no
                // such stock row", instead of a misleading "field is required".
                $parts[$index]['inventory_id'] = $resolvedInventoryId ?: $part['store_product_id'];
            }

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
