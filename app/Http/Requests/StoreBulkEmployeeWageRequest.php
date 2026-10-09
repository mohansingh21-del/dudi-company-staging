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

    /**
     * The existing revision is matched on effective_from against a date
     * column, so normalise it first — otherwise a differently formatted date
     * misses the revision already held for that day.
     */
    protected function prepareForValidation()
    {
        // Form-data and query strings send "true"/"false", which the boolean
        // rule rejects.
        foreach (['overwrite', 'is_active'] as $flag) {
            if (!$this->filled($flag)) {
                continue;
            }

            $value = filter_var($this->input($flag), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value !== null) {
                $this->merge([$flag => $value]);
            }
        }

        if (!$this->filled('effective_from')) {
            return;
        }

        try {
            $this->merge([
                'effective_from' => \Carbon\Carbon::parse($this->effective_from)->toDateString(),
            ]);
        } catch (\Throwable $th) {
            // Leave it alone; the date rule reports it.
        }
    }

    public function rules()
    {
        return [
            'effective_from' => 'required|date',
            'is_active' => 'nullable|boolean',

            // Accepted for older clients only: a submit for a date that already
            // carries a revision always updates it, flag or no flag.
            'overwrite' => 'nullable|boolean',

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

    /**
     * The categories in this payload that are already stored under the same
     * effective date — what a save would overwrite.
     */
    public function clashingCategories()
    {
        $categories = collect($this->input('rates', []))
            ->pluck('skill_category')
            ->filter()
            ->unique();

        if ($categories->isEmpty() || !$this->filled('effective_from')) {
            return collect();
        }

        return EmployeeWage::query()
            ->whereIn('skill_category', $categories->all())
            ->whereDate('effective_from', $this->effective_from)
            ->pluck('skill_category');
    }

    public function messages(): array
    {
        return [
            'rates.*.skill_category.distinct' => 'Each skill category may appear only once in a submission.',
        ];
    }
}
