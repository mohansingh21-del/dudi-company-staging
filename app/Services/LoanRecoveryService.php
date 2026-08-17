<?php

namespace App\Services;

use App\Models\Penalty;
use App\Models\LoanRecoveryInstallment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanRecoveryService
{
    /**
     * Maximum percentage of gross salary that can be
     * recovered for a loan in one month.
     */
    public const MAX_PERCENTAGE = 25;

    /**
     * Get the maximum loan deduction allowed for a salary.
     */
    public function maximumMonthlyDeduction(float $grossSalary): float
    {
        return round(
            $grossSalary * (self::MAX_PERCENTAGE / 100),
            2
        );
    }

    /**
     * Get all active loan recoveries for an employee.
     */
    public function getActiveLoans(
        int $employeeId,
        int $month,
        int $year
    ) {
        return Penalty::where('employee_id', $employeeId)
            ->where('recovery_type', 'loans')
            ->where('calculation_amount', '>', 0)
            ->where(function ($query) use ($month, $year) {

                /*
                 * Loan must have started on or before
                 * this payroll period.
                 */
                $query->where(function ($q) use ($month, $year) {
                    $q->whereNull('calculation_first_year')
                        ->orWhere(function ($q2) use ($month, $year) {
                            $q2->where('calculation_first_year', '<', $year)
                                ->orWhere(function ($q3) use ($month, $year) {
                                    $q3->where(
                                        'calculation_first_year',
                                        $year
                                    )->where(
                                        'calculation_first_month',
                                        '<=',
                                        $month
                                    );
                                });
                        });
                });
            })
            ->get();
    }

    /**
     * Calculate/create the installment for one loan
     * for the requested payroll month.
     *
     * This uses the immutable calculation_amount.
     */
    public function calculateMonthlyInstallment(
        Penalty $loan,
        float $grossSalary,
        int $month,
        int $year,
        ?int $payrollId = null
    ): LoanRecoveryInstallment {

        if ($loan->recovery_type !== 'loans') {
            throw new \InvalidArgumentException(
                'Penalty is not a loan recovery.'
            );
        }

        $loanAmount = (float) $loan->calculation_amount;

        /*
         * Amount already deducted before this month.
         */
        $alreadyRecovered = (float) LoanRecoveryInstallment::where(
            'penalty_id',
            $loan->id
        )
            ->where(function ($query) use ($month, $year) {
                $query->where('year', '<', $year)
                    ->orWhere(function ($q) use ($month, $year) {
                        $q->where('year', $year)
                            ->where('month', '<', $month);
                    });
            })
            ->whereIn('status', ['deducted', 'pending'])
            ->sum('installment_amount');

        /*
         * If this month's installment already exists,
         * return it instead of creating a duplicate.
         */
        $existing = LoanRecoveryInstallment::where(
            'penalty_id',
            $loan->id
        )
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if ($existing) {
            return $existing;
        }

        $remainingBefore = max(
            0,
            $loanAmount - $alreadyRecovered
        );

        /*
         * Loan completely recovered.
         */
        if ($remainingBefore <= 0) {
            return LoanRecoveryInstallment::create([
                'penalty_id' => $loan->id,
                'employee_id' => $loan->employee_id,
                'month' => $month,
                'year' => $year,
                'installment_number' => 0,
                'opening_balance' => 0,
                'salary_basis' => $grossSalary,
                'maximum_allowed_amount' =>
                $this->maximumMonthlyDeduction($grossSalary),
                'installment_amount' => 0,
                'closing_balance' => 0,
                'status' => 'skipped',
                'payroll_id' => $payrollId,
                'remarks' => 'Loan already fully recovered.',
            ]);
        }

        $maximumAllowed = $this->maximumMonthlyDeduction(
            $grossSalary
        );

        /*
         * Actual installment = lesser of:
         *
         * 1. Remaining loan
         * 2. 25% salary limit
         */
        $installmentAmount = min(
            $remainingBefore,
            $maximumAllowed
        );

        $closingBalance = max(
            0,
            $remainingBefore - $installmentAmount
        );

        $installmentNumber = LoanRecoveryInstallment::where(
            'penalty_id',
            $loan->id
        )->count() + 1;

        return LoanRecoveryInstallment::create([
            'penalty_id' => $loan->id,
            'employee_id' => $loan->employee_id,
            'month' => $month,
            'year' => $year,

            'installment_number' =>
            $installmentNumber,

            'opening_balance' =>
            $remainingBefore,

            'salary_basis' =>
            $grossSalary,

            'maximum_allowed_amount' =>
            $maximumAllowed,

            'installment_amount' =>
            $installmentAmount,

            'closing_balance' =>
            $closingBalance,

            'status' =>
            $payrollId ? 'deducted' : 'pending',

            'payroll_id' =>
            $payrollId,

            'deduction_date' =>
            $payrollId ? now()->toDateString() : null,
        ]);
    }

    /**
     * Calculate all loan deductions for an employee
     * for one payroll month.
     */
    public function calculateEmployeeLoanDeduction(
        int $employeeId,
        float $grossSalary,
        int $month,
        int $year,
        ?int $payrollId = null
    ): array {

        $loans = $this->getActiveLoans(
            $employeeId,
            $month,
            $year
        );

        $installments = collect();

        foreach ($loans as $loan) {

            $installment = $this->calculateMonthlyInstallment(
                $loan,
                $grossSalary,
                $month,
                $year,
                $payrollId
            );

            if ($installment->installment_amount > 0) {
                $installments->push($installment);
            }
        }

        return [
            'total' => round(
                $installments->sum('installment_amount'),
                2
            ),

            'installments' => $installments,
        ];
    }
}
