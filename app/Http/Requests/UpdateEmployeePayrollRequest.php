<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeePayrollRequest extends FormRequest
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

    'salary_type' => 'required|in:monthly,daily_wage',

    'basic_salary' => 'nullable|numeric|min:0',

    'daily_wage' => 'nullable|numeric|min:0',

    'pf_applicable' => 'nullable|boolean',

    'pf_number' => 'nullable|string|max:255',

    'bank_name' => 'nullable|string|max:255',

    'bank_account_number' => 'nullable|string|max:255',

    'ifsc_code' => 'nullable|string|max:50',

    'mess_deduction_applicable' => 'nullable|boolean',

    'other_deduction_appliacble' => 'nullable|boolean',

    'other_deduction' => 'nullable|numeric|min:0',

    'rest_days' => 'nullable|integer|min:0|max:31',

        ];
    }
}
