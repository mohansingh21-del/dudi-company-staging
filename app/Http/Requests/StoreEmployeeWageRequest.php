<?php

namespace App\Http\Requests;

use App\Models\EmployeeWage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeWageRequest extends FormRequest
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'skill_category' => [
                'required',
                Rule::in(EmployeeWage::SKILL_CATEGORIES),
                // A category can be revised many times, but only once per date.
                Rule::unique('employee_wages', 'skill_category')
                    ->where('effective_from', $this->effective_from),
            ],

            'minimum_basic' => 'required|numeric|min:0',
            'dearness_allowance' => 'required|numeric|min:0',
            'overtime_rate' => 'nullable|numeric|min:0',

            'effective_from' => 'required|date',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'skill_category.unique' => 'A wage rate for this skill category already exists from that date. Edit it, or pick a different effective date.',
        ];
    }
}
