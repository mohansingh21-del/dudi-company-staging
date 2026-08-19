<?php

namespace App\Imports;

use App\Models\Leave;
use App\Models\Employee;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class LeaveImport implements ToCollection, WithHeadingRow
{
    protected $errors = [];

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            if (empty($row['employee_code'])) {
                continue;
            }

            $employee = Employee::where(
                'employee_code',
                trim((string) $row['employee_code'])
            )->first();

            if (!$employee) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'message' => 'Employee Code not found: ' . $row['employee_code']
                ];
                continue;
            }

            // Required: a leave with no type contributes to nothing on the Form E
            // register, so it would import cleanly and then silently disappear.
            if (empty($row['leave_type'])) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'message' => "Leave Type is required for Employee {$employee->employee_code}. Allowed: " . $this->allowedLeaveTypes()
                ];
                continue;
            }

            // Case-insensitive so "earned leave" matches "Earned Leave".
            $leaveType = LeaveType::whereRaw('LOWER(name) = ?', [
                strtolower(trim((string) $row['leave_type']))
            ])->first();

            if (!$leaveType) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'message' => 'Leave Type not found: ' . $row['leave_type'] . '. Allowed: ' . $this->allowedLeaveTypes()
                ];
                continue;
            }

            // Same rule as the apply form: a paid block the admin has not
            // given a quota to has nothing to draw against, so the row is
            // rejected rather than imported against an entitlement of zero.
            // Unpaid leave is uncapped and never gated.
            if (! $leaveType->canApply()) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'message' => "Employee {$employee->employee_code}: " . $leaveType->quotaMissingMessage()
                ];
                continue;
            }

            $fromDate = $this->parseDate($row['from_date'] ?? null);
            $toDate = $this->parseDate($row['to_date'] ?? null);

            if (!$fromDate || !$toDate) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'message' => "Invalid date format for Employee {$employee->employee_code}."
                ];
                continue;
            }

            $existingLeave = Leave::where('employee_id', $employee->id)
                ->where(function ($query) use ($fromDate, $toDate) {
                    $query->whereBetween('from_date', [$fromDate->toDateString(), $toDate->toDateString()])
                        ->orWhereBetween('to_date', [$fromDate->toDateString(), $toDate->toDateString()])
                        ->orWhere(function ($q) use ($fromDate, $toDate) {
                            $q->where('from_date', '<=', $fromDate->toDateString())
                              ->where('to_date', '>=', $toDate->toDateString());
                        });
                })
                ->exists();

            if ($existingLeave) {
                $this->errors[] = [
                    'row' => $index + 2,
                    'message' => "Leave already exists for Employee {$employee->employee_code} between {$fromDate->format('d/m/Y')} and {$toDate->format('d/m/Y')}."
                ];
                continue;
            }

            Leave::create([
                'employee_id'   => $employee->id,
                'leave_type_id' => $leaveType->id,
                'from_date'     => $fromDate->toDateString(),
                'to_date'       => $toDate->toDateString(),
                'reason'        => $row['reason'] ?? null,
                'status'        => $row['status'] ?? 'pending',
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

    /**
     * The four Form E blocks, listed in error messages so whoever fixes the
     * sheet can see the accepted spellings without opening the master.
     */
    private function allowedLeaveTypes(): string
    {
        return LeaveType::whereNotNull('register_group')
            ->orderBy('id')
            ->pluck('name')
            ->implode(', ');
    }

    public function getErrors()
    {
        return $this->errors;
    }
}