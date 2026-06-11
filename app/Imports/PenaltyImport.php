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

            $employee = Employee::where('employee_code', $row['employee_code'])->first();
            if (!$employee) {
                continue;
            }

            // Parse date: handle Excel serial format or standard formats
            try {
                if (is_numeric($row['penalty_date'])) {
                    $parsedDate = Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row['penalty_date']));
                } else {
                    $parsedDate = Carbon::parse($row['penalty_date']);
                }
            } catch (\Exception $e) {
                $parsedDate = Carbon::now();
            }

            Penalty::create([
                'employee_id' => $employee->id,
                'penalty_date' => $parsedDate->toDateString(),
                'month' => $parsedDate->month,
                'year' => $parsedDate->year,
                'reason' => $row['reason'],
                'amount' => $row['amount'],
            ]);
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
