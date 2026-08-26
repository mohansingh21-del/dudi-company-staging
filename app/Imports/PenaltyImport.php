<?php

namespace App\Imports;

use App\Models\Penalty;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class PenaltyImport implements ToCollection, WithHeadingRow, WithValidation
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (empty($row['employee_code'])) {
                continue;
            }

            $employee = Employee::where('employee_code', trim((string) $row['employee_code']))->first();
            if (!$employee) {
                continue;
            }

            // Parse date: handle Excel serial format or standard formats
            $parsedDate = $this->parseDate($row['penalty_date'] ?? null) ?? Carbon::now();

            Penalty::create([
                'employee_id' => $employee->id,
                'penalty_date' => $parsedDate->toDateString(),
                'month' => $parsedDate->month,
                'year' => $parsedDate->year,
                'reason' => $row['reason'],
                'amount' => $row['amount'],

                // Snapshot: required for LoanRecoveryService to pick this up
                'calculation_amount' => $row['amount'],
                'calculation_date' => $parsedDate->toDateString(),
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
            '*.employee_code' => 'required|exists:employees,employee_code',
            '*.penalty_date' => 'required',
            '*.reason' => 'required|string',
            '*.amount' => 'required|numeric|min:0.01',
        ];
    }
}

