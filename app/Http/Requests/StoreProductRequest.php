<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sub_category_id' => 'required|exists:sub_categories,id',
            'name' => 'required|string|max:255|unique:products,name',
            'min_stock' => 'required|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Product name already exists.',
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
