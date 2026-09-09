<?php

namespace App\Http\Requests\Traits;

use Illuminate\Contracts\Validation\Validator;

/**
 * A service record that draws parts from outside draws them from exactly one
 * store, against exactly one job card that store raised. Both live on the
 * record rather than on each part row, so neither can be expressed as a
 * per-field rule in rules() — they depend on what the spare_parts array holds.
 *
 * Shared by the store and update requests so the two cannot drift.
 */
trait ValidatesStoreSourcedSpareParts
{
    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $validator) {
            $parts = $this->input('spare_parts');

            if (!is_array($parts)) {
                return;
            }

            $hasStorePart = false;
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['source']) && $part['source'] === 'store') {
                    $hasStorePart = true;
                    break;
                }
            }

            if (!$hasStorePart) {
                return;
            }

            if (!$this->filled('store_id')) {
                $validator->errors()->add(
                    'store_id',
                    'Store is required when a spare part is issued from an outside store.'
                );
            }

            if (!$this->filled('job_card_number')) {
                $validator->errors()->add(
                    'job_card_number',
                    'Job card number is required when a spare part is issued from an outside store.'
                );
            }
        });
    }
}
