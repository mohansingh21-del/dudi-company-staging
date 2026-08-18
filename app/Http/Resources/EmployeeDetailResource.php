<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Employee identity together with the payroll configuration held in
 * employee_payrolls — salary, statutory identifiers and bank details, which
 * EmployeeResource deliberately leaves out. The payroll block is null when the
 * employee has no configuration row yet.
 */
class EmployeeDetailResource extends JsonResource
{
    /**
     * Shifts are a small master table, so they are read once per request and
     * shared across every row rather than looked up per employee.
     */
    private static $shifts;

    private static function shiftName($shiftId)
    {
        if ($shiftId === null) {
            return null;
        }

        if (self::$shifts === null) {
            self::$shifts = \App\Models\Shift::all()->keyBy('id');
        }

        return optional(self::$shifts->get($shiftId))->shift_name;
    }

    /**
     * The stored Aadhaar is decrypted on access, which throws if the row was
     * written under a different APP_KEY. This endpoint is unpaginated, so one
     * unreadable row must not take down the whole response.
     */
    private static function aadhaarNumber($payroll)
    {
        try {
            return $payroll->aadhaar_number;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function toArray($request)
    {
        $payroll = $this->employeePayroll;

        // shift_id is a computed accessor that hits the database, so resolve
        // it once instead of once per field that needs it.
        $shiftId = $this->shift_id;

        return [
            'id' => $this->id,

            'employee_code' => $this->employee_code,
            'name' => $this->name,
            'surname' => $this->surname,
            'full_name' => $this->full_name,
            'father_name' => $this->father_name,
            'dob' => optional($this->dob)->format('d F Y'),
            'gender' => $this->gender,
            'nationality' => $this->nationality,
            'education_level' => $this->education_level,
            'identification_mark' => $this->identification_mark,

            'mobile' => $this->mobile,
            'emergency_contact' => $this->emergency_contact,

            'address' => $this->address,
            'permanent_address' => $this->permanent_address,

            'joining_date' => optional($this->joining_date)->format('d F Y'),
            'service_book_no' => $this->service_book_no,
            'employee_type' => $this->employee_type,

            'status' => $this->is_active,
            'date_of_exit' => optional($this->date_of_exit)->format('d F Y'),
            'reason_for_exit' => $this->reason_for_exit,

            'department_id' => $this->department_id,
            'department' => optional($this->department)->name,
            'designation_id' => $this->designation_id,
            'designation' => optional($this->designation)->name,
            'skill_category' => $this->skill_category,
            'skill_category_label' => $this->skill_category_label,
            'site_id' => $this->site_id,
            'site' => optional($this->site)->site_name,
            'place_of_employment' => $this->place_of_employment,
            'place_of_employment_label' => $this->place_of_employment_label,
            'supervisor_id' => $this->supervisor_id,
            'supervisor' => optional($this->supervisor)->name,
            'shift_id' => $shiftId,
            'shift' => self::shiftName($shiftId),
            'relay_id' => $this->relay_id,
            'relay_shift' => optional($this->relay)->name,
            'relay_name' => optional($this->relay)->name,

            'photo_url' => $this->photo_path ? asset('storage/' . $this->photo_path) : null,
            'signature_url' => $this->signature_path ? asset('storage/' . $this->signature_path) : null,
            'remarks' => $this->remarks,

            'payroll' => $payroll ? [
                'id' => $payroll->id,

                'salary_type' => $payroll->salary_type,
                'basic_salary' => $payroll->basic_salary,
                'daily_wage' => $payroll->daily_wage,

                'pf_applicable' => $payroll->pf_applicable,
                'pf_number' => $payroll->pf_number,
                'pf_amount' => $payroll->pf_amount,
                'uan' => $payroll->uan,
                'esic_ip_number' => $payroll->esic_ip_number,
                'lwf_number' => $payroll->lwf_number,
                'pan' => $payroll->pan,

                'aadhaar_number' => self::aadhaarNumber($payroll),
                'aadhaar_last4' => $payroll->aadhaar_last4,

                'bank_name' => $payroll->bank_name,
                'bank_account_number' => $payroll->bank_account_number,
                'ifsc_code' => $payroll->ifsc_code,

                'mess_deduction_applicable' => $payroll->mess_deduction_applicable,
                'mess_deduction_amount' => $payroll->mess_deduction_amount,
                'other_deduction_appliacble' => $payroll->other_deduction_appliacble,
                'other_deduction' => $payroll->other_deduction,

                'rest_days' => $payroll->rest_days,
                'effective_from' => optional($payroll->effective_from)->format('d F Y'),
                'is_active' => $payroll->is_active,
            ] : null,
        ];
    }
}
