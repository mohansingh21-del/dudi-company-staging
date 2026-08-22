<?php

namespace App\Imports;

use App\Models\AttendanceProcessed;
use App\Models\Employee;
use App\Models\EmployeePayroll;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class AttendanceImport implements ToCollection, WithHeadingRow, WithValidation
{
    public function collection(Collection $rows)
    {
        $errors = [];
        $data = [];

        // Rest days accepted so far in this sheet, keyed employee|YYYY-MM. The
        // rows are not inserted until step 2, so the monthly cap has to count
        // them itself or one upload could hand an employee a whole month of
        // rest days.
        $pendingRestDays = [];

        /*
        |--------------------------------------------------------------------------
        | STEP 1: NORMALIZE + VALIDATE
        |--------------------------------------------------------------------------
        */
        foreach ($rows as $index => $row) {

            $rowNumber = $index + 2;

            if (empty($row['employee_code'])) {
                continue;
            }

            $employee = Employee::where('employee_code', trim($row['employee_code']))->first();

            if (!$employee) {
                $errors["row_{$rowNumber}"][] =
                    "Employee code {$row['employee_code']} not found.";
                continue;
            }

            $payroll = EmployeePayroll::where('employee_id', $employee->id)
                ->where('is_active', 1)
                ->first();

            try {
                $date = $this->parseDate($row['date'] ?? null);
                if (!$date) {
                    $errors["row_{$rowNumber}"][] = "Invalid date format.";
                    continue;
                }
            } catch (\Exception $e) {
                $errors["row_{$rowNumber}"][] = "Invalid date format.";
                continue;
            }

            if ($date->isFuture()) {
                $errors["row_{$rowNumber}"][] = "Date cannot be in the future.";
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | CLEAN STATUS + TIME
            |--------------------------------------------------------------------------
            */
            $status = strtolower(trim((string) ($row['status'] ?? 'present')));
            $status = str_replace(['-', '_'], ' ', $status);
            $status = preg_replace('/\s+/', ' ', $status);

            $statusMap = [
                'present'   => 'present',
                'absent'    => 'absent',
                'half day'  => 'half_day',
                'half_day'  => 'half_day',
                'leave'     => 'leave',
                'rest day'  => 'rest_day',
                'rest_day'  => 'rest_day',
            ];

            $status = $statusMap[$status] ?? $status;

            $checkInRaw = trim((string) ($row['check_in'] ?? ''));
            $checkOutRaw = trim((string) ($row['check_out'] ?? ''));

            $checkInRaw = ($checkInRaw === '' ? null : $checkInRaw);
            $checkOutRaw = ($checkOutRaw === '' ? null : $checkOutRaw);

            /*
            |--------------------------------------------------------------------------
            | DUPLICATE CHECK
            |--------------------------------------------------------------------------
            */
            $exists = AttendanceProcessed::where('employee_id', $employee->id)
                ->whereDate('date', $date->format('Y-m-d'))
                ->exists();

            if ($exists) {
                $errors["row_{$rowNumber}"][] =
                    "Attendance already exists for {$employee->employee_code}.";
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | REST DAY MONTHLY CAP
            |--------------------------------------------------------------------------
            */
            if ($status === 'rest_day') {
                $monthKey = $employee->id . '|' . $date->format('Y-m');

                $capMessage = \App\Services\LeaveBalanceService::restDayCapMessage(
                    $employee->id,
                    $date->format('Y-m-d'),
                    [],
                    $pendingRestDays[$monthKey] ?? 0
                );

                if ($capMessage) {
                    $errors["row_{$rowNumber}"][] = $capMessage;
                    continue;
                }

                $pendingRestDays[$monthKey] = ($pendingRestDays[$monthKey] ?? 0) + 1;
            }

            /*
            |--------------------------------------------------------------------------
            | PRESENT VALIDATION
            |--------------------------------------------------------------------------
            */
            if ($status === 'present') {

                if (!$checkInRaw || !$checkOutRaw) {
                    $errors["row_{$rowNumber}"][] =
                        "Check In/Out required for Present.";
                    continue;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | HALF DAY VALIDATION
            |--------------------------------------------------------------------------
            */
            if ($status === 'half_day') {

                if (!$checkInRaw || !$checkOutRaw) {
                    $errors["row_{$rowNumber}"][] =
                        "Half Day requires check-in and check-out.";
                    continue;
                }
            }

            $checkInTime = null;
            $checkOutTime = null;

            if ($checkInRaw) {
                try {
                    $checkInTime = $this->parseTime($checkInRaw, $date);
                } catch (\Exception $e) {
                    $errors["row_{$rowNumber}"][] = "Invalid check-in time format.";
                    continue;
                }
            }

            if ($checkOutRaw) {
                try {
                    $checkOutTime = $this->parseTime($checkOutRaw, $date);
                } catch (\Exception $e) {
                    $errors["row_{$rowNumber}"][] = "Invalid check-out time format.";
                    continue;
                }
            }

            if ($checkInTime && $checkOutTime && $checkOutTime->lessThanOrEqualTo($checkInTime)) {
                $errors["row_{$rowNumber}"][] = "Check-out time must be after check-in time.";
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | STORE TEMP DATA
            |--------------------------------------------------------------------------
            */
            $data[] = [
                'row' => $rowNumber,
                'employee' => $employee,
                'payroll' => $payroll,
                'date' => $date,
                'status' => $status,
                'check_in' => $checkInTime,
                'check_out' => $checkOutTime,
                'remarks' => $row['remarks'] ?? null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | STOP IF ERRORS
        |--------------------------------------------------------------------------
        */
        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        /*
        |--------------------------------------------------------------------------
        | STEP 2: INSERT
        |--------------------------------------------------------------------------
        */
        foreach ($data as $item) {

            $employee = $item['employee'];
            $date = $item['date'];
            $status = $item['status'];
            $checkIn = $item['check_in'];
            $checkOut = $item['check_out'];
            $workingHours = 0;

            if (in_array($status, ['present', 'half_day']) && $checkIn && $checkOut) {
                $workingHours = round(
                    $checkIn->diffInMinutes($checkOut) / 60,
                    2
                );
            }

            switch ($status) {
                case 'present':
                    $attendanceStatus = 'present';
                    break;
                case 'absent':
                    $attendanceStatus = 'absent';
                    break;
                case 'half_day':
                    $attendanceStatus = 'half_day';
                    break;
                case 'leave':
                    $attendanceStatus = 'leave';
                    break;
                case 'rest_day':
                    $attendanceStatus = 'rest_day';
                    break;
                default:
                    $attendanceStatus = 'present';
                    break;
            }

            AttendanceProcessed::create([
                'employee_id' => $employee->id,
                'date' => $date->format('Y-m-d'),
                'check_in' => $checkIn ? $checkIn->toDateTimeString() : null,
                'check_out' => $checkOut ? $checkOut->toDateTimeString() : null,
                'working_hours' => $workingHours,
                'late_minutes' => 0,
                'early_exit_minutes' => 0,
                'attendance_status' => $attendanceStatus,
                'remarks' => $item['remarks'],
            ]);
        }
    }

    public function rules(): array
    {
        return [
            '*.employee_code' => ['required', 'exists:employees,employee_code'],
            '*.date' => ['required'],
            '*.check_in' => ['nullable'],
            '*.check_out' => ['nullable'],
            '*.status' => ['nullable'],
            '*.remarks' => ['nullable'],
        ];
    }

    public function customValidationMessages()
    {
        return [
            '*.employee_code.required' => 'Employee Code is required.',
            '*.employee_code.exists' => 'Employee Code does not exist.',
            '*.date.required' => 'Date is required.',
        ];
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
            throw new \Exception("Invalid date format: {$value}");
        }
    }

    private function parseTime($value, Carbon $baseDate): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $c = Carbon::instance($value);
            return $baseDate->copy()->setTime($c->hour, $c->minute, $c->second);
        }

        if (is_numeric($value)) {
            $totalSeconds = round((float) $value * 86400);
            $hours = (int) floor($totalSeconds / 3600);
            $minutes = (int) floor(($totalSeconds % 3600) / 60);
            $seconds = (int) ($totalSeconds % 60);
            return $baseDate->copy()->setTime($hours, $minutes, $seconds);
        }

        $value = trim((string) $value);

        $formats = [
            'H:i:s',
            'H:i',
            'g:i A',
            'g:i:s A',
            'h:i A',
            'h:i:s A',
            'G:i:s',
            'G:i',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed !== false) {
                    return $baseDate->copy()->setTime($parsed->hour, $parsed->minute, $parsed->second);
                }
            } catch (\Throwable $ex) {
                continue;
            }
        }

        try {
            $parsed = Carbon::parse($value);
            return $baseDate->copy()->setTime($parsed->hour, $parsed->minute, $parsed->second);
        } catch (\Throwable $e) {
            throw new \Exception("Invalid time format: {$value}");
        }
    }
}