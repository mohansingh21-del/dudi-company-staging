<?php

namespace App\Http\Requests;

use App\Models\EmployeeWage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeWageRequest extends FormRequest
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
        $id = $this->route('employee_wage');
        $existing = EmployeeWage::find($id);

        // Either half of the pair may be left out of a partial update, so fall
        // back to what is already stored when checking for a clash.
        $effectiveFrom = $this->filled('effective_from')
            ? $this->effective_from
            : optional(optional($existing)->effective_from)->toDateString();

        return [
            'skill_category' => [
                'sometimes',
                'required',
                Rule::in(EmployeeWage::SKILL_CATEGORIES),
                Rule::unique('employee_wages', 'skill_category')
                    ->where('effective_from', $effectiveFrom)
                    ->ignore($id),
            ],

            'minimum_basic' => 'sometimes|required|numeric|min:0',
            'dearness_allowance' => 'sometimes|required|numeric|min:0',
            'overtime_rate' => 'nullable|numeric|min:0',

            'effective_from' => 'sometimes|required|date',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * Changing only the date can collide just as easily as changing only the
     * category, and the rule above is skipped when skill_category is absent.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->has('skill_category') || !$this->filled('effective_from')) {
                return;
            }

            $id = $this->route('employee_wage');
            $existing = EmployeeWage::find($id);

            if (!$existing) {
                return;
            }

            $clash = EmployeeWage::where('skill_category', $existing->skill_category)
                ->whereDate('effective_from', $this->effective_from)
                ->where('id', '!=', $id)
                ->exists();

            if ($clash) {
                $validator->errors()->add(
                    'effective_from',
                    'A wage rate for this skill category already exists from that date.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'skill_category.unique' => 'A wage rate for this skill category already exists from that date. Edit it, or pick a different effective date.',
        ];
    }
}
