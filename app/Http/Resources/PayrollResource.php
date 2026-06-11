<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                => $this->id,
            'employee_id'       => $this->employee_id,
            'employee_name'     => optional($this->employee)->name,
            'employee_code'     => optional($this->employee)->employee_code,
            'department'        => optional(optional($this->employee)->department)->name,
            'designation'       => optional(optional($this->employee)->designation)->name,
            'site'              => optional(optional($this->employee)->site)->site_name,
            'month'             => $this->month,
            'year'              => $this->year,
            'payroll_month'     => $this->month && $this->year
                ? \Carbon\Carbon::create($this->year, $this->month)->format('F Y') : null,
            'basic_salary'      => (float) $this->basic_salary,
            'shift_allowance'   => (float) $this->shift_allowance,
            'incentives'        => (float) $this->incentives,
            'gross_salary'      => (float) $this->gross_salary,
            'present_days'      => (int) $this->present_days,
            'half_days'         => (int) $this->half_days,
            'absent_days'       => (int) $this->absent_days,
            'leave_days'        => (int) $this->leave_days,
            'paid_leave_days'   => (int) $this->paid_leave_days,
            'unpaid_leave_days' => (int) $this->unpaid_leave_days,
            'pf_deduction'      => (float) $this->pf_deduction,
            'mess_deduction'    => (float) $this->mess_deduction,
            'leave_deduction'   => (float) $this->leave_deduction,
            'penalty_deduction' => (float) $this->penalty_deduction,
            'other_deduction'   => (float) $this->other_deduction,
            'net_salary'        => (float) $this->net_salary,
            'status'            => $this->status,
            'generated_by'      => $this->generated_by,
            'created_at'        => $this->created_at?->toDateTimeString(),
            'updated_at'        => $this->updated_at?->toDateTimeString(),
        ];
    }
}
