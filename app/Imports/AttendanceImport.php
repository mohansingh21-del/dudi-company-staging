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
        $restCounter = [];

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
                $date = Carbon::createFromFormat('d/m/Y', trim($row['date']));
            } catch (\Exception $e) {
                $errors["row_{$rowNumber}"][] = "Invalid date format.";
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | CLEAN STATUS + TIME
            |--------------------------------------------------------------------------
            */
            $status = strtolower(trim($row['status'] ?? 'present'));
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
                ->whereDate('date', $date)
                ->exists();

            if ($exists) {
                $errors["row_{$rowNumber}"][] =
                    "Attendance already exists for {$employee->employee_code}.";
                continue;
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
            | HALF DAY VALIDATION (FIXED)
            |--------------------------------------------------------------------------
            */
            if ($status === 'half_day') {

                if (!$checkInRaw || !$checkOutRaw) {
                    $errors["row_{$rowNumber}"][] =
                        "Half Day requires check-in and check-out.";
                    continue;
                }
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
                'check_in' => $checkInRaw,
                'check_out' => $checkOutRaw,
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

            $checkIn = null;
            $checkOut = null;
            $workingHours = 0;

            /*
            |--------------------------------------------------------------------------
            | PRESENT / HALF DAY TIME HANDLING (FIXED)
            |--------------------------------------------------------------------------
            */
            if (in_array($status, ['present', 'half_day'])) {

                $checkIn = Carbon::createFromFormat('H:i', $item['check_in']);
                $checkOut = Carbon::createFromFormat('H:i', $item['check_out']);

                $workingHours = round(
                    $checkIn->diffInMinutes($checkOut) / 60,
                    2
                );
            }

            /*
            |--------------------------------------------------------------------------
            | STATUS MAP (FINAL)
            |--------------------------------------------------------------------------
            */
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
                'date' => $date,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
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
            '*.date' => ['required', 'date_format:d/m/Y', 'before_or_equal:today'],
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
            '*.date.date_format' => 'Date must be in d/m/Y format.',
        ];
    }
}