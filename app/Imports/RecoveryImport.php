<?php

namespace App\Imports;

use App\Models\Employee;
use App\Models\RecoveryUploadRow;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class RecoveryImport implements
    ToCollection,
    WithHeadingRow
{
    public function __construct(
        protected int $uploadId
    ) {}

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            $row = $row->toArray();

            $errors = [];

            /*
             * EMPLOYEE CODE
             */
            $employeeCode = trim(
                (string) ($row['emp_code'] ?? '')
            );

            if ($employeeCode === '') {

                $errors['employee_code'][] =
                    'Employee code is required.';

                $employee = null;
            } else {

                $employee = Employee::where(
                    'employee_code',
                    $employeeCode
                )->first();

                if (!$employee) {

                    $errors['employee_code'][] =
                        'Employee does not exist.';
                }
            }

            /*
             * NAME
             */
            $name = trim(
                (string) ($row['name'] ?? '')
            );

            if ($name === '') {

                $errors['name'][] =
                    'Employee name is required.';
            } elseif (!$employee) {

                $errors['name'][] =
                    'Employee name cannot be verified because the employee code does not exist.';
            } else {

                $employeeName = strtolower(
                    trim($employee->full_name)
                );

                $uploadedName = strtolower(
                    trim($name)
                );

                if ($employeeName !== $uploadedName) {

                    $errors['name'][] =
                        'Employee name does not match.';
                }
            }

            /*
             * RECOVERY TYPE
             */
            $allowedTypes = [
                'damage',
                'loss',
                'fine',
                'advance',
                'loans',
            ];

            $type = strtolower(
                trim(
                    (string) (
                        $row['recovery_type'] ?? ''
                    )
                )
            );

            if (!in_array(
                $type,
                $allowedTypes,
                true
            )) {

                $errors['recovery_type'][] =
                    'Invalid recovery type. Allowed values: damage, loss, fine, advance, loans.';
            }

            /*
             * PARTICULARS
             */
            $particulars =
                isset($row['particulars'])
                ? trim((string) $row['particulars'])
                : null;

            /*
             * DAMAGE / LOSS DATE
             */
            $rawDamageDate =
                $row['date_of_damage_loss'] ?? null;

            $damageDate = $this->parseDate(
                $rawDamageDate
            );

            if (
                $rawDamageDate !== null &&
                $rawDamageDate !== '' &&
                $damageDate === null
            ) {

                $errors['damage_loss_date'][] =
                    'Invalid damage/loss date.';
            }

            /*
             * AMOUNT
             */
            $rawAmount =
                $row['amount'] ?? null;

            $amount = null;

            if (
                $rawAmount === null ||
                $rawAmount === ''
            ) {

                $errors['amount'][] =
                    'Amount is required.';
            } elseif (!is_numeric($rawAmount)) {

                $errors['amount'][] =
                    'Amount must be numeric.';
            } else {

                $amount = (float) $rawAmount;

                if ($amount <= 0) {

                    $errors['amount'][] =
                        'Amount must be greater than zero.';
                }
            }

            /*
             * SHOW CAUSE
             */
            $showCause = $this->normalizeYesNo(
                $row['show_cause_issued'] ?? null
            );

            if (!in_array(
                $showCause,
                ['yes', 'no'],
                true
            )) {

                $errors['show_cause_issued'][] =
                    'Show cause issued must be Yes or No.';
            }

            /*
             * WITNESS
             */
            $witness =
                isset($row['explanation_witness'])
                ? trim((string) $row['explanation_witness'])
                : null;

            /*
             * INSTALLMENTS
             */
            $rawInstallments =
                $row['number_installments'] ?? null;

            $installments = null;

            if (
                $rawInstallments !== null &&
                $rawInstallments !== ''
            ) {

                if (!is_numeric($rawInstallments)) {

                    $errors['number_of_installments'][] =
                        'Number of installments must be numeric.';
                } else {

                    $installments =
                        (int) $rawInstallments;

                    if ($installments <= 0) {

                        $errors['number_of_installments'][] =
                            'Number of installments must be greater than zero.';
                    }
                }
            }

            /*
             * FIRST MONTH
             */
            $firstMonth =
                $this->normalizeMonthYear(
                    $row['first_month_year'] ?? null
                );

            if (
                !empty($row['first_month_year']) &&
                !$firstMonth
            ) {

                $errors['first_month_year'][] =
                    'First Month/Year must be in YYYY-MM format.';
            }

            /*
             * LAST MONTH
             */
            $lastMonth =
                $this->normalizeMonthYear(
                    $row['last_month_year'] ?? null
                );

            if (
                !empty($row['last_month_year']) &&
                !$lastMonth
            ) {

                $errors['last_month_year'][] =
                    'Last Month/Year must be in YYYY-MM format.';
            }

            /*
             * MONTH RANGE
             */
            if (
                $firstMonth &&
                $lastMonth &&
                $firstMonth > $lastMonth
            ) {

                $errors['last_month_year'][] =
                    'Last Month/Year cannot be before First Month/Year.';
            }

            /*
             * COMPLETE RECOVERY DATE
             */
            $rawCompleteDate =
                $row['complete_recovery_date'] ?? null;

            $completeDate =
                $this->parseDate(
                    $rawCompleteDate
                );

            if (
                $rawCompleteDate !== null &&
                $rawCompleteDate !== '' &&
                $completeDate === null
            ) {

                $errors['complete_recovery_date'][] =
                    'Invalid complete recovery date.';
            }

            /*
             * REMARKS
             */
            $remarks =
                isset($row['remarks'])
                ? trim((string) $row['remarks'])
                : null;

            /*
             * SAVE STAGING ROW
             */
            RecoveryUploadRow::create([

                'recovery_upload_id' =>
                $this->uploadId,

                'employee_code' =>
                $employeeCode,

                'name' =>
                $name,

                'recovery_type' =>
                $type,

                'particulars' =>
                $particulars,

                'damage_loss_date' =>
                $damageDate,

                'amount' =>
                $amount,

                'show_cause_issued' =>
                $showCause,

                'explanation_witness' =>
                $witness,

                'number_of_installments' =>
                $installments,

                'first_month_year' =>
                $firstMonth,

                'last_month_year' =>
                $lastMonth,

                'complete_recovery_date' =>
                $completeDate,

                'remarks' =>
                $remarks,

                'errors' =>
                empty($errors)
                    ? null
                    : $errors,

                'is_valid' =>
                empty($errors),
            ]);
        }
    }

    private function parseDate(mixed $value): ?string
    {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        try {

            if (
                is_numeric($value) &&
                (float) $value > 0
            ) {

                return ExcelDate
                    ::excelToDateTimeObject($value)
                    ->format('Y-m-d');
            }

            return Carbon::parse($value)
                ->format('Y-m-d');
        } catch (\Throwable $e) {

            return null;
        }
    }

    private function normalizeYesNo(
        mixed $value
    ): ?string {

        if ($value === null) {
            return null;
        }

        $value = strtolower(
            trim((string) $value)
        );

        if (in_array(
            $value,
            ['yes', 'y', '1', 'true'],
            true
        )) {
            return 'yes';
        }

        if (in_array(
            $value,
            ['no', 'n', '0', 'false'],
            true
        )) {
            return 'no';
        }

        return $value;
    }

    private function normalizeMonthYear(
        mixed $value
    ): ?string {

        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        /*
         * Excel serial date
         */
        if (
            is_numeric($value) &&
            (float) $value > 0
        ) {

            try {

                return ExcelDate
                    ::excelToDateTimeObject($value)
                    ->format('Y-m');
            } catch (\Throwable $e) {

                return null;
            }
        }

        $value = trim(
            (string) $value
        );

        /*
         * YYYY-MM
         */
        if (preg_match(
            '/^\d{4}-(0[1-9]|1[0-2])$/',
            $value
        )) {
            return $value;
        }

        /*
         * YYYY-MM-DD
         */
        if (preg_match(
            '/^\d{4}-(0[1-9]|1[0-2])-\d{2}$/',
            $value
        )) {

            return substr(
                $value,
                0,
                7
            );
        }

        try {

            return Carbon::parse($value)
                ->format('Y-m');
        } catch (\Throwable $e) {

            return null;
        }
    }
}
