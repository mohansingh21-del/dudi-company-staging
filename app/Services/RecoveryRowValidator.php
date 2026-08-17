<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\RecoveryUploadRow;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class RecoveryRowValidator
{
    /**
     * Allowed recovery types.
     */
    private array $allowedTypes = [
        'damage',
        'loss',
        'fine',
        'advance',
        'loans',
    ];


    /**
     * Validate complete row.
     *
     * IMPORTANT:
     * Validation uses raw_values whenever available.
     *
     * This means:
     *
     * amount = "ABC"
     *
     * can remain stored as:
     *
     * amount = null
     * raw_values.amount = "ABC"
     *
     * and the validation error remains available.
     */
    public function validate(
        RecoveryUploadRow $row
    ): array {

        $errors = [];

        $raw = $row->raw_values ?? [];


        /*
         * =========================================================
         * EMPLOYEE CODE
         * =========================================================
         */

        $employeeCodeRaw =
            array_key_exists('employee_code', $raw)
            ? $raw['employee_code']
            : $row->employee_code;

        $employeeCode =
            trim((string) ($employeeCodeRaw ?? ''));

        $employee = null;

        if ($this->isEmpty($employeeCodeRaw)) {

            $errors['employee_code'][] =
                'Employee code is required.';
        } else {

            /*
             * Employee code must be text.
             */
            if (!is_string($employeeCodeRaw)) {

                $errors['employee_code'][] =
                    'Employee code must be text.';
            }

            /*
             * Find employee.
             */
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
         * =========================================================
         * NAME
         * =========================================================
         */

        $nameRaw =
            array_key_exists('name', $raw)
            ? $raw['name']
            : $row->name;

        $name =
            trim((string) ($nameRaw ?? ''));

        if ($this->isEmpty($nameRaw)) {

            $errors['name'][] =
                'Employee name is required.';
        } else {

            /*
             * Name should be text.
             */
            if (!is_string($nameRaw)) {

                $errors['name'][] =
                    'Employee name must be text.';
            }

            /*
             * If employee code exists and employee
             * was found, compare names.
             */
            if ($employee) {

                $employeeName =
                    mb_strtolower(
                        trim(
                            (string) $employee->full_name
                        )
                    );

                $uploadedName =
                    mb_strtolower($name);

                if (
                    $employeeName !==
                    $uploadedName
                ) {

                    $errors['name'][] =
                        'Employee name does not match the employee code.';
                }
            } elseif (
                !$this->isEmpty($employeeCodeRaw)
            ) {

                /*
                 * Employee code was supplied but does
                 * not exist.
                 *
                 * Still return a name error so frontend
                 * receives all applicable errors.
                 */
                $errors['name'][] =
                    'Employee name cannot be verified because the employee code does not exist.';
            }
        }


        /*
         * =========================================================
         * RECOVERY TYPE
         * =========================================================
         */

        $typeRaw =
            array_key_exists('recovery_type', $raw)
            ? $raw['recovery_type']
            : $row->recovery_type;

        $type =
            mb_strtolower(
                trim((string) ($typeRaw ?? ''))
            );

        if ($this->isEmpty($typeRaw)) {

            $errors['recovery_type'][] =
                'Recovery type is required.';
        } else {

            if (!is_string($typeRaw)) {

                $errors['recovery_type'][] =
                    'Recovery type must be text.';
            }

            if (
                !in_array(
                    $type,
                    $this->allowedTypes,
                    true
                )
            ) {

                $errors['recovery_type'][] =
                    'Invalid recovery type. Allowed values: damage, loss, fine, advance, loans.';
            }
        }


        /*
         * =========================================================
         * PARTICULARS
         * =========================================================
         */

        $particularsRaw =
            array_key_exists('particulars', $raw)
            ? $raw['particulars']
            : $row->particulars;

        if (
            !$this->isEmpty($particularsRaw) &&
            !is_string($particularsRaw)
        ) {

            $errors['particulars'][] =
                'Particulars must be text.';
        }


        /*
         * =========================================================
         * DAMAGE / LOSS DATE
         * =========================================================
         */

        $damageDateRaw =
            array_key_exists(
                'damage_loss_date',
                $raw
            )
            ? $raw['damage_loss_date']
            : $row->damage_loss_date;

        if (
            !$this->isEmpty($damageDateRaw)
        ) {

            if (
                !$this->isValidDate(
                    $damageDateRaw
                )
            ) {

                $errors['damage_loss_date'][] =
                    'Invalid damage/loss date. Please enter a valid date.';
            }
        }


        /*
         * =========================================================
         * AMOUNT
         * =========================================================
         */

        $amountRaw =
            array_key_exists('amount', $raw)
            ? $raw['amount']
            : $row->amount;

        if (
            $this->isEmpty($amountRaw)
        ) {

            $errors['amount'][] =
                'Amount is required.';
        } elseif (
            !is_numeric($amountRaw)
        ) {

            $errors['amount'][] =
                'Amount must be numeric.';
        } else {

            if ((float) $amountRaw <= 0) {

                $errors['amount'][] =
                    'Amount must be greater than zero.';
            }
        }


        /*
         * =========================================================
         * SHOW CAUSE
         * =========================================================
         */

        $showCauseRaw =
            array_key_exists(
                'show_cause_issued',
                $raw
            )
            ? $raw['show_cause_issued']
            : $row->show_cause_issued;

        $showCause =
            mb_strtolower(
                trim(
                    (string) (
                        $showCauseRaw ?? ''
                    )
                )
            );

        if (
            $this->isEmpty($showCauseRaw)
        ) {

            $errors['show_cause_issued'][] =
                'Show cause issued is required.';
        } elseif (
            !in_array(
                $showCause,
                [
                    'yes',
                    'no',
                    'y',
                    'n',
                    '1',
                    '0',
                    'true',
                    'false',
                ],
                true
            )
        ) {

            $errors['show_cause_issued'][] =
                'Show cause issued must be Yes or No.';
        }


        /*
         * =========================================================
         * EXPLANATION WITNESS
         * =========================================================
         */

        $witnessRaw =
            array_key_exists(
                'explanation_witness',
                $raw
            )
            ? $raw['explanation_witness']
            : $row->explanation_witness;

        if (
            !$this->isEmpty($witnessRaw) &&
            !is_string($witnessRaw)
        ) {

            $errors['explanation_witness'][] =
                'Explanation witness must be text.';
        }


        /*
         * =========================================================
         * NUMBER OF INSTALLMENTS
         * =========================================================
         */

        $installmentsRaw =
            array_key_exists(
                'number_of_installments',
                $raw
            )
            ? $raw['number_of_installments']
            : $row->number_of_installments;

        if (
            !$this->isEmpty($installmentsRaw)
        ) {

            if (
                !is_numeric($installmentsRaw)
            ) {

                $errors['number_of_installments'][] =
                    'Number of installments must be numeric.';
            } elseif (
                (int) $installmentsRaw <= 0
            ) {

                $errors['number_of_installments'][] =
                    'Number of installments must be greater than zero.';
            }
        }


        /*
         * =========================================================
         * FIRST MONTH / YEAR
         * =========================================================
         */

        $firstMonthRaw =
            array_key_exists(
                'first_month_year',
                $raw
            )
            ? $raw['first_month_year']
            : $row->first_month_year;

        $firstMonth =
            $this->normalizeMonthYear(
                $firstMonthRaw
            );

        if (
            !$this->isEmpty($firstMonthRaw) &&
            !$firstMonth
        ) {

            $errors['first_month_year'][] =
                'First Month/Year must be in YYYY-MM format.';
        }


        /*
         * =========================================================
         * LAST MONTH / YEAR
         * =========================================================
         */

        $lastMonthRaw =
            array_key_exists(
                'last_month_year',
                $raw
            )
            ? $raw['last_month_year']
            : $row->last_month_year;

        $lastMonth =
            $this->normalizeMonthYear(
                $lastMonthRaw
            );

        if (
            !$this->isEmpty($lastMonthRaw) &&
            !$lastMonth
        ) {

            $errors['last_month_year'][] =
                'Last Month/Year must be in YYYY-MM format.';
        }


        /*
         * =========================================================
         * FIRST MONTH > LAST MONTH
         * =========================================================
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
         * =========================================================
         * COMPLETE RECOVERY DATE
         * =========================================================
         */

        $completeDateRaw =
            array_key_exists(
                'complete_recovery_date',
                $raw
            )
            ? $raw['complete_recovery_date']
            : $row->complete_recovery_date;

        if (
            !$this->isEmpty($completeDateRaw)
        ) {

            if (
                !$this->isValidDate(
                    $completeDateRaw
                )
            ) {

                $errors['complete_recovery_date'][] =
                    'Invalid complete recovery date. Please enter a valid date.';
            }
        }


        /*
         * =========================================================
         * REMARKS
         * =========================================================
         */

        $remarksRaw =
            array_key_exists(
                'remarks',
                $raw
            )
            ? $raw['remarks']
            : $row->remarks;

        if (
            !$this->isEmpty($remarksRaw) &&
            !is_string($remarksRaw)
        ) {

            $errors['remarks'][] =
                'Remarks must be text.';
        }


        return $errors;
    }


    /**
     * Validate and update errors/is_valid.
     */
    public function validateAndUpdate(
        RecoveryUploadRow $row
    ): RecoveryUploadRow {

        $errors =
            $this->validate($row);

        $row->errors =
            empty($errors)
            ? null
            : $errors;

        $row->is_valid =
            empty($errors);

        return $row;
    }


    /**
     * Parse date.
     */
    public function parseDate(
        mixed $value
    ): ?string {

        if (
            $this->isEmpty($value)
        ) {
            return null;
        }

        try {

            /*
             * Excel serial date.
             */
            if (
                is_numeric($value) &&
                (float) $value > 0
            ) {

                return ExcelDate
                    ::excelToDateTimeObject(
                        $value
                    )
                    ->format('Y-m-d');
            }

            return Carbon::parse(
                $value
            )->format('Y-m-d');
        } catch (\Throwable $e) {

            return null;
        }
    }


    /**
     * Validate date.
     */
    public function isValidDate(
        mixed $value
    ): bool {

        return $this->parseDate(
            $value
        ) !== null;
    }


    /**
     * Normalize Yes/No.
     */
    public function normalizeYesNo(
        mixed $value
    ): ?string {

        if (
            $this->isEmpty($value)
        ) {
            return null;
        }

        $value =
            mb_strtolower(
                trim((string) $value)
            );

        if (
            in_array(
                $value,
                [
                    'yes',
                    'y',
                    '1',
                    'true',
                ],
                true
            )
        ) {

            return 'yes';
        }

        if (
            in_array(
                $value,
                [
                    'no',
                    'n',
                    '0',
                    'false',
                ],
                true
            )
        ) {

            return 'no';
        }

        return $value;
    }


    /**
     * Normalize YYYY-MM.
     */
    public function normalizeMonthYear(
        mixed $value
    ): ?string {

        if (
            $this->isEmpty($value)
        ) {
            return null;
        }

        /*
         * Excel serial date.
         */
        if (
            is_numeric($value) &&
            (float) $value > 0
        ) {

            try {

                return ExcelDate
                    ::excelToDateTimeObject(
                        $value
                    )
                    ->format('Y-m');
            } catch (\Throwable $e) {

                return null;
            }
        }

        $value =
            trim((string) $value);

        /*
         * YYYY-MM.
         */
        if (
            preg_match(
                '/^\d{4}-(0[1-9]|1[0-2])$/',
                $value
            )
        ) {

            return $value;
        }

        /*
         * YYYY-MM-DD.
         */
        if (
            preg_match(
                '/^\d{4}-(0[1-9]|1[0-2])-\d{2}$/',
                $value
            )
        ) {

            return substr(
                $value,
                0,
                7
            );
        }

        return null;
    }


    /**
     * Empty-value helper.
     */
    private function isEmpty(
        mixed $value
    ): bool {

        return $value === null ||
            (
                is_string($value) &&
                trim($value) === ''
            );
    }
}
