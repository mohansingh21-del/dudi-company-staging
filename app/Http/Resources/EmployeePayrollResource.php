<?php

namespace App\Http\Resources;

use App\Services\LeaveBalanceService;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePayrollResource extends JsonResource
{
    /**
     * The stored Aadhaar is decrypted on access, which throws if the row was
     * written under a different APP_KEY. One unreadable row must not take down
     * the whole listing, so fall back to null.
     */
    private function aadhaarNumber()
    {
        try {
            return $this->aadhaar_number;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function toArray($request)
    {
        return [

            'id' => $this->id,

            'employee_id' => $this->employee_id,

            'employee_name' => optional($this->employee)->name,
            
            'employee_code' => optional($this->employee)->employee_code,


            'salary_type' => $this->salary_type,

            'basic_salary' => $this->basic_salary,

            'daily_wage' => $this->daily_wage,

            'pf_applicable' => $this->pf_applicable,
            
            'department' => optional(optional($this->employee)->department)->name,

            'pf_number' => $this->pf_number,

            'uan' => $this->uan,

            'esic_ip_number' => $this->esic_ip_number,

            'lwf_number_applicable' => $this->lwf_number_applicable,

            'lwf_number' => $this->lwf_number,

            'pan' => $this->pan,

            // Key name is kept for the existing clients; the value is the full
            // Aadhaar number, no longer just the last four digits.
            'aadhaar_last4' => $this->aadhaarNumber(),

            'bank_name' => $this->bank_name,

            'bank_account_number' => $this->bank_account_number,

            'ifsc_code' => $this->ifsc_code,

            'mess_deduction_applicable' => $this->mess_deduction_applicable,

            'other_deduction_appliacble' => $this->other_deduction_appliacble,

            'other_deduction' => $this->other_deduction,
            'pf_amount' => $this->pf_amount,
            'mess_deduction_amount' => $this->mess_deduction_amount,

            // Display only. The paid-rest-day cap is establishment-wide and
            // lives on the leave master, not on this row: the employee_payrolls
            // .rest_days column it used to come from is dead (always 0, read by
            // nothing) and is deliberately not exposed. Payroll and attendance
            // read this same accessor, so the screen matches what they pay.
            'rest_days' => LeaveBalanceService::monthlyPaidRestDays(),

            'is_active' => $this->is_active
        ];
    }
}
