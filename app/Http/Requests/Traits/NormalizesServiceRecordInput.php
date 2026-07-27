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

        if (!empty($merge)) {
            $this->merge($merge);
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
