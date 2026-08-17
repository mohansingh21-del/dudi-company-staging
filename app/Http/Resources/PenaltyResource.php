<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PenaltyResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,

            /*
             * Employee
             */
            'employee_id' => $this->employee_id,
            'employee_name' => optional($this->employee)->name,
            'employee_code' => optional($this->employee)->employee_code,

            /*
             * Current / editable register data
             */
            'penalty_date' => $this->penalty_date
                ? $this->penalty_date->format('Y-m-d')
                : null,

            'penalty_month' => $this->penalty_date
                ? $this->penalty_date->format('F Y')
                : null,

            'month' => $this->month,
            'year' => $this->year,

            'recovery_type' => $this->recovery_type,

            'reason' => $this->reason,

            'particulars' => $this->particulars,

            'amount' => $this->amount,

            'show_cause_issued' => $this->show_cause_issued,

            'explanation_heard_in_presence' =>
            $this->explanation_heard_in_presence,

            'number_of_installments' =>
            $this->number_of_installments,

            'first_month' =>
            $this->first_month,

            'first_year' =>
            $this->first_year,

            'first_month_year' => ($this->first_month && $this->first_year)
                ? sprintf(
                    '%02d/%04d',
                    $this->first_month,
                    $this->first_year
                )
                : null,

            'last_month' =>
            $this->last_month,

            'last_year' =>
            $this->last_year,

            'last_month_year' => ($this->last_month && $this->last_year)
                ? sprintf(
                    '%02d/%04d',
                    $this->last_month,
                    $this->last_year
                )
                : null,

            'date_of_complete_recovery' =>
            $this->date_of_complete_recovery
                ? $this->date_of_complete_recovery->format('Y-m-d')
                : null,

            'remarks' => $this->remarks,

            /*
             * Payroll snapshot
             *
             * Normally Angular doesn't need these, but returning
             * them can be useful for debugging/audit.
             */
            'calculation_snapshot' => [
                'amount' => $this->calculation_amount,
                'recovery_type' => $this->calculation_recovery_type,
                'particulars' => $this->calculation_particulars,

                'date' => $this->calculation_date
                    ? $this->calculation_date->format('Y-m-d')
                    : null,

                'number_of_installments' =>
                $this->calculation_number_of_installments,

                'first_month' =>
                $this->calculation_first_month,

                'first_year' =>
                $this->calculation_first_year,

                'last_month' =>
                $this->calculation_last_month,

                'last_year' =>
                $this->calculation_last_year,
            ],

            'created_at' => $this->created_at
                ? $this->created_at->toDateTimeString()
                : null,

            'updated_at' => $this->updated_at
                ? $this->updated_at->toDateTimeString()
                : null,
        ];
    }
}
