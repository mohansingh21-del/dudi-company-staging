<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Map a product to a store and give it an opening quantity and a threshold.
 *
 * Re-posting an existing pair replenishes it rather than failing, mirroring
 * InventoryController@store — so there is no unique rule here.
 */
class StoreStoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id'   => 'required|integer|exists:stores,id',
            'product_id' => 'required|integer|exists:products,id',
            'quantity'   => 'required|numeric|min:0',
            'threshold'  => 'nullable|numeric|min:0',
            'remarks'    => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.required'   => 'Store is required.',
            'store_id.exists'     => 'Selected store does not exist.',
            'product_id.required' => 'Product is required.',
            'product_id.exists'   => 'Selected product does not exist.',
            'quantity.required'   => 'Quantity is required.',
        ];
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
