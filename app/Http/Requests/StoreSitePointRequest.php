<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSitePointRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'site_id' => [
                'required',
                'exists:sites,id',
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('site_points', 'name')
                    ->where(function ($query) {
                        return $query->where('site_id', $this->input('site_id'));
                    }),
            ],
            'type' => [
                'required',
                'in:loading,dumping',
            ],
            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],
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

    /**
     * Custom validation messages.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'site_id.required'  => 'Site is required.',
            'site_id.exists'    => 'Selected site does not exist.',
            'name.required'     => 'Point name is required.',
            'name.unique'       => 'This point name already exists for the selected site.',
            'type.required'     => 'Point type is required.',
            'type.in'           => 'Point type must be loading or dumping.',
        ];
    }
}
