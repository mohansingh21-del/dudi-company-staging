<?php

namespace App\Http\Requests;

use App\Models\EmployeePayroll;
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
     * Normalise the identifiers before validation so the format rules below
     * do not fail on casing or separators the user cannot see.
     */
    protected function prepareForValidation()
    {
        $this->merge(array_filter([
            'pan' => $this->pan ? strtoupper(trim($this->pan)) : null,
            'aadhaar_number' => $this->aadhaar_number ? preg_replace('/\D/', '', $this->aadhaar_number) : null,
        ], fn($value) => $value !== null));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $id = $this->route('employee_payroll');

        return [

    'salary_type' => 'required|in:monthly,daily_wage,piece_rate',

    'basic_salary' => 'nullable|numeric|min:0',

    'daily_wage' => 'nullable|numeric|min:0',

    'pf_applicable' => 'nullable|boolean',

    'pf_number' => 'nullable|string|max:255',

    'uan' => 'nullable|digits:12|unique:employee_payrolls,uan,' . $id,

    'esic_ip_number' => 'nullable|string|max:20',

    'lwf_number_applicable' => 'nullable|boolean',

    'lwf_number' => 'nullable|string|max:255',

    'pan' => 'nullable|string|size:10|regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/|unique:employee_payrolls,pan,' . $id,

    'aadhaar_number' => ['nullable', 'digits:12', $this->uniqueAadhaarRule($id)],

    'bank_name' => 'nullable|string|max:255',

    'bank_account_number' => 'nullable|string|max:255',

    'ifsc_code' => 'nullable|string|max:50',

    'mess_deduction_applicable' => 'nullable|boolean',

    'other_deduction_appliacble' => 'nullable|boolean',

    'other_deduction' => 'nullable|numeric|min:0',
    'pf_amount' => 'nullable|numeric|min:0',
    'mess_deduction_amount' => 'nullable|numeric|min:0',

    'rest_days' => 'nullable|integer|min:0|max:31',

        ];
    }

    /**
     * Aadhaar is encrypted non-deterministically, so uniqueness has to be
     * checked against the SHA-256 hash rather than the stored value.
     */
    protected function uniqueAadhaarRule($id): \Closure
    {
        return function ($attribute, $value, $fail) use ($id) {
            $exists = EmployeePayroll::where('aadhaar_hash', hash('sha256', $value))
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                $fail('This Aadhaar number is already registered against another employee.');
            }
        };
    }

    public function messages(): array
    {
        return [
            'pan.regex' => 'PAN must be in the format ABCDE1234F.',
            'aadhaar_number.digits' => 'Aadhaar number must be 12 digits.',
            'uan.digits' => 'UAN must be 12 digits.',
        ];
    }
}
