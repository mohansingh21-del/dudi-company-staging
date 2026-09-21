<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The form now sends site_id[]. A single scalar site_id is still accepted so
     * older clients keep working.
     */
    protected function prepareForValidation()
    {
        if ($this->has('site_id') && !is_array($this->site_id)) {
            $this->merge(['site_id' => [$this->site_id]]);
        }
    }

    public function rules(): array
    {
        return [
            'site_id' => 'required|array|min:1',
            'site_id.*' => 'required|integer|distinct|exists:sites,id',
            'holiday_name' => 'required|string|max:255',
            'holiday_date' => 'required|date',
            'holiday_type' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'site_id.required' => 'Holiday site is required.',
            'site_id.array' => 'Holiday site must be a list of sites.',
            'site_id.min' => 'Select at least one site.',
            'site_id.*.exists' => 'One of the selected sites does not exist.',
            'site_id.*.distinct' => 'The same site is selected more than once.',
            'holiday_name.required' => 'Holiday name is required.',
            'holiday_name.max' => 'Holiday name cannot be longer than 255 characters.',
            'holiday_type.max' => 'Holiday type cannot be longer than 255 characters.',
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
