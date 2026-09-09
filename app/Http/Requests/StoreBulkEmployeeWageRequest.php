<?php

namespace App\Http\Requests;

use App\Models\EmployeeWage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One revision date, the whole Form B header in one submit — the four skill
 * categories are set together because that is how the printed grid reads.
 */
class StoreBulkEmployeeWageRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'effective_from' => 'required|date',
            'is_active' => 'nullable|boolean',

            'rates' => 'required|array|min:1|max:' . count(EmployeeWage::SKILL_CATEGORIES),

            'rates.*.skill_category' => [
                'required',
                Rule::in(EmployeeWage::SKILL_CATEGORIES),
                // The unique index is on category + date, so the same category
                // twice in one payload would collide against itself.
                'distinct',
            ],

            'rates.*.minimum_basic' => 'required|numeric|min:0',
            'rates.*.dearness_allowance' => 'required|numeric|min:0',
            'rates.*.overtime_rate' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'rates.*.skill_category.distinct' => 'Each skill category may appear only once in a submission.',
        ];
    }
}
