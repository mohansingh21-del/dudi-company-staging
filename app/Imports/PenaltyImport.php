<?php

namespace App\Imports;

use App\Enums\RecoveryType;
use App\Models\Penalty;
use App\Models\Employee;
use App\Rules\HasActivePayroll;
use App\Services\LoanRecoveryService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class PenaltyImport implements ToCollection, WithHeadingRow, WithValidation
{
    /**
     * The sheet has no recovery_type column, so imported rows all get
     * the same type.
     */
    public const DEFAULT_RECOVERY_TYPE = RecoveryType::FINE;

    private LoanRecoveryService $recoveryService;

    public function __construct(?LoanRecoveryService $recoveryService = null)
    {
        $this->recoveryService = $recoveryService ?? app(LoanRecoveryService::class);
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (empty($row['employee_code'])) {
                continue;
            }

            $employee = Employee::with('activePayroll')
                ->where('employee_code', trim((string) $row['employee_code']))
                ->first();
            if (!$employee) {
                continue;
            }

            // Parse date: handle Excel serial format or standard formats
            $parsedDate = $this->parseDate($row['penalty_date'] ?? null) ?? Carbon::now();

            $amount = (float) $row['amount'];

            /*
             * The same 25%-cap schedule the single-penalty form computes,
             * so imported and manually created penalties carry identical
             * installment fields instead of leaving them null.
             *
             * Computed per row rather than per file, so an earlier row for
             * the same employee already counts against the monthly budget.
             */
            $plan = $this->recoveryService->scheduleFor(
                $employee,
                $amount,
                $parsedDate
            );

            Penalty::create([
                'employee_id' => $employee->id,
                'penalty_date' => $parsedDate->toDateString(),
                'month' => $parsedDate->month,
                'year' => $parsedDate->year,
                'recovery_type' => self::DEFAULT_RECOVERY_TYPE,
                'reason' => $row['reason'],
                'amount' => $amount,

                'number_of_installments' => $plan['number_of_installments'],
                'installment_amount' => $plan['installment_amount'],
                'first_month' => $plan['first_month'],
                'first_year' => $plan['first_year'],
                'last_month' => $plan['last_month'],
                'last_year' => $plan['last_year'],
                'date_of_complete_recovery' => $plan['date_of_complete_recovery'],

                // Snapshot: required for LoanRecoveryService to pick this up
                'calculation_amount' => $amount,
                'calculation_recovery_type' => self::DEFAULT_RECOVERY_TYPE,
                'calculation_date' => $parsedDate->toDateString(),
                'calculation_number_of_installments' => $plan['number_of_installments'],
                'calculation_first_month' => $plan['first_month'],
                'calculation_first_year' => $plan['first_year'],
                'calculation_last_month' => $plan['last_month'],
                'calculation_last_year' => $plan['last_year'],
            ]);
        }
    }

    private function parseDate($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value))->startOfDay();
        }

        $value = trim((string) $value);

        $formats = [
            'd/m/Y',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'Y-m-d',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'd-m-Y',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'm/d/Y',
            'm/d/Y H:i:s',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed !== false) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable $ex) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function rules(): array
    {
        return [
            '*.employee_code' => [
                'required',
                'exists:employees,employee_code',
                new HasActivePayroll('employee_code'),
            ],
            '*.penalty_date' => 'required',
            '*.reason' => 'required|string',
            '*.amount' => 'required|numeric|min:0.01',
        ];
    }
}
