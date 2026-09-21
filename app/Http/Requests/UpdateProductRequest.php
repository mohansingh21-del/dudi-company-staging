<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product') ?? $this->route('id');

        return [
            'sub_category_id' => 'required|exists:sub_categories,id',
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'name')
                    ->ignore($productId)
                    ->where(function ($query) {
                        return $query->where('sub_category_id', $this->input('sub_category_id'));
                    }),
            ],
            'min_stock' => 'required|integer|min:0',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation()
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => is_string($this->input('name'))
                    ? trim(preg_replace('/\s+/', ' ', $this->input('name')))
                    : $this->input('name'),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'This product name already exists in the selected subcategory.',
            'name.required' => 'Product name is required.',
            'sub_category_id.required' => 'Subcategory is required.',
            'sub_category_id.exists' => 'Selected subcategory is invalid.',
            'min_stock.required' => 'Minimum stock reserve is required.',
            'min_stock.integer' => 'Minimum stock reserve must be an integer.',
            'min_stock.min' => 'Minimum stock reserve must be at least 0.',
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
