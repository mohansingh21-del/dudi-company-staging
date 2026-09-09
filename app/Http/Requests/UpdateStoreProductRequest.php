<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The single edit endpoint for a store inventory row.
 *
 * Every field is optional so a caller can change the stock, the floor, the
 * active flag, or any combination, in one request — but at least one of them
 * must be present, otherwise the call is a silent no-op.
 *
 * `quantity` is the new TOTAL stock for this row, not a delta — whatever the
 * caller sends becomes `store_products.quantity`. The units already issued out
 * of this row are historical fact and cannot be edited away, so `left_quantity`
 * is recomputed as (new total - already issued). The movement that difference
 * represents is still written to `inventory_logs`, so the balance and its
 * history never drift apart.
 */
class UpdateStoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity'  => 'nullable|numeric|min:0',
            'threshold' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|in:0,1',
            'remarks'   => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.numeric'  => 'Quantity must be a number.',
            'quantity.min'      => 'Quantity cannot be negative.',
            'threshold.numeric' => 'Threshold must be a number.',
            'threshold.min'     => 'Threshold cannot be negative.',
        ];
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $validator) {
            $changesNothing = !$this->filled('quantity')
                && !$this->filled('threshold')
                && !$this->filled('is_active');

            if ($changesNothing) {
                $validator->errors()->add(
                    'quantity',
                    'Nothing to update. Send at least one of quantity, threshold or is_active.'
                );
            }
        });
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422)
        );
    }
}
