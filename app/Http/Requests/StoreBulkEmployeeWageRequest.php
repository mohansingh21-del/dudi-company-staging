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
     * The duplicate check compares effective_from against a date column, so
     * normalise it first — otherwise a differently formatted date slips past
     * and silently overwrites the revision already held for that day.
     */
    protected function prepareForValidation()
    {
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

            // Saving over a revision that already exists is a deliberate edit,
            // not something a create should do by accident, so the caller has
            // to say so. Without it a clash is rejected below.
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
     * A revision already stored under the submitted date would be overwritten
     * silently, so reject it unless the caller asked for that.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty() || $this->boolean('overwrite')) {
                return;
            }

            $clashing = $this->clashingCategories();

            if ($clashing->isEmpty()) {
                return;
            }

            $validator->errors()->add(
                'effective_from',
                'A wage revision already exists from ' . $this->effectiveFromLabel() . ' for '
                    . $clashing->map(fn ($category) => ucwords(str_replace('_', '-', $category), '-'))
                        ->join(', ', ' and ')
                    . '. Edit that revision, or pick a different effective date.'
            );
        });
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

    private function effectiveFromLabel(): string
    {
        try {
            return \Carbon\Carbon::parse($this->effective_from)->format('d M Y');
        } catch (\Throwable $th) {
            return (string) $this->effective_from;
        }
    }

    public function messages(): array
    {
        return [
            'rates.*.skill_category.distinct' => 'Each skill category may appear only once in a submission.',
        ];
    }
}
