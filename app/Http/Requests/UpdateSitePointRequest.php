<?php

namespace App\Http\Requests;

use App\Models\SitePoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSitePointRequest extends FormRequest
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
        $id = $this->route('id');

        return [
            'site_id' => [
                'sometimes',
                'required',
                'exists:sites,id',
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique('site_points', 'name')
                    ->ignore($id)
                    ->where(function ($query) {
                        return $query->where('site_id', $this->resolvedSiteId());
                    }),
            ],
            'type' => [
                'sometimes',
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
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Site the point will belong to after the update — the incoming
     * site_id when supplied, otherwise the one already stored.
     *
     * @return mixed
     */
    protected function resolvedSiteId()
    {
        if ($this->filled('site_id')) {
            return $this->input('site_id');
        }

        $point = SitePoint::find($this->route('id'));

        return $point ? $point->site_id : null;
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
            'site_id.exists'  => 'Selected site does not exist.',
            'name.required'   => 'Point name is required.',
            'name.unique'     => 'This point name already exists for the selected site.',
            'type.in'         => 'Point type must be loading or dumping.',
        ];
    }
}
