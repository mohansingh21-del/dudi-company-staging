<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class AddInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'store_id' => 'required|integer|exists:stores,id',
            'product_id' => 'required|exists:products,id',
            // Optional. Product names repeat across sub-categories, so when the
            // form sends the category it picked from, the product must sit
            // under it — a mismatch means the wrong product id was sent.
            'category_id' => 'nullable|integer|exists:categories,id',
            'sub_category_id' => 'nullable|integer|exists:sub_categories,id',
            'quantity' => 'required|numeric|min:0.01',
            'remarks' => 'nullable|string|max:255',
        ];
    }

    /**
     * Check the product belongs to the category / sub-category sent with it.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $errors = $validator->errors();

            if ($errors->has('product_id') || $errors->has('category_id') || $errors->has('sub_category_id')) {
                return;
            }

            if (!$this->filled('category_id') && !$this->filled('sub_category_id')) {
                return;
            }

            $product = Product::with('subCategory')->find($this->input('product_id'));

            if ($this->filled('sub_category_id')
                && (int) $product->sub_category_id !== (int) $this->input('sub_category_id')) {
                $errors->add('product_id', 'Selected product does not belong to the selected sub-category.');
                return;
            }

            if ($this->filled('category_id')
                && (int) optional($product->subCategory)->category_id !== (int) $this->input('category_id')) {
                $errors->add('product_id', 'Selected product does not belong to the selected category.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'store_id.required' => 'Store is required.',
            'store_id.exists' => 'Selected store is invalid.',
            'product_id.required' => 'Product is required.',
            'product_id.exists' => 'Selected product is invalid.',
            'category_id.exists' => 'Selected category is invalid.',
            'sub_category_id.exists' => 'Selected sub-category is invalid.',
            'quantity.required' => 'Quantity is required.',
            'quantity.numeric' => 'Quantity must be a number.',
            'quantity.min' => 'Quantity must be at least 0.01.',
        ];
    }

    protected function failedValidation(Validator $validator)
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
