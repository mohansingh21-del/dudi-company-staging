<?php

namespace App\Imports;

use App\Models\AttendanceProcessed;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
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
        foreach ($rows as $index => $row) {

            if (empty($row['employee_code'])) {
                continue;
            }

            $employee = Employee::where(
                'employee_code',
                trim($row['employee_code'])
            )->first();

            if (!$employee) {
                throw ValidationException::withMessages([
                    "row_" . ($index + 2) =>
                    "Employee code {$row['employee_code']} not found."
                ]);
            }

            $attendanceDate = Carbon::createFromFormat(
                'd/m/Y',
                $row['date']
            );

            // Duplicate attendance check
            $alreadyExists = AttendanceProcessed::where(
                'employee_id',
                $employee->id
            )
                ->whereDate('date', $attendanceDate)
                ->exists();

            if ($alreadyExists) {
                throw ValidationException::withMessages([
                    "row_" . ($index + 2) =>
                    "Attendance already exists for Employee {$employee->employee_code} on {$attendanceDate->format('d-m-Y')}"
                ]);
            }

            $checkIn = Carbon::parse(
                $attendanceDate->format('Y-m-d') . ' ' . $row['check_in']
            );

            $checkOut = Carbon::parse(
                $attendanceDate->format('Y-m-d') . ' ' . $row['check_out']
            );

            if ($checkOut->lessThanOrEqualTo($checkIn)) {
                throw ValidationException::withMessages([
                    "row_" . ($index + 2) =>
                    "Check-out must be greater than check-in."
                ]);
            }

            $workingHours = round(
                $checkIn->diffInMinutes($checkOut) / 60,
                2
            );

            // Employee shift
            //  $shift = Shift::find($employee->shift_id);
            // $shiftAssignment = EmployeeShiftAssignment::where('employee_id', $employee->id)
            //     ->whereDate('from_date', '<=', $attendanceDate)
            //     ->where(function ($query) use ($attendanceDate) {
            //         $query->whereNull('to_date')
            //             ->orWhereDate('to_date', '>=', $attendanceDate);
            //     })
            //     ->first();
            $shiftAssignment = EmployeeShiftAssignment::where('employee_id', $employee->id)->first();
            $shift = $shiftAssignment
                ? Shift::find($shiftAssignment->shift_id)
                : null;
            $lateMinutes = 0;
            $earlyExitMinutes = 0;
            if (!$shiftAssignment) {
                throw ValidationException::withMessages([
                    "row_" . ($index + 2) =>
                    "No shift assignment found for Employee {$employee->employee_code} on {$attendanceDate->format('d-m-Y')}"
                ]);
            }
            if ($shift) {

                $shiftStart = Carbon::parse(
                    $attendanceDate->format('Y-m-d') . ' ' . $shift->start_time
                );

                $shiftEnd = Carbon::parse(
                    $attendanceDate->format('Y-m-d') . ' ' . $shift->end_time
                );

                $lateMinutes = max(
                    0,
                    $shiftStart->diffInMinutes($checkIn, false)
                );

                $lateMinutes = abs(min(0, $lateMinutes));

                $earlyExitMinutes = max(
                    0,
                    $checkOut->diffInMinutes($shiftEnd, false) * -1
                );
            }
            $hasApprovedLeave = \App\Models\Leave::where('employee_id', $employee->id)
    ->where('status', 'approved')
    ->whereDate('from_date', '<=', $attendanceDate->format('Y-m-d'))
    ->whereDate('to_date', '>=', $attendanceDate->format('Y-m-d'))
    ->exists();

$status = $row['status'] ?? 'present';

if (
    strtolower($status) === 'present' &&
    $hasApprovedLeave
) {
    throw ValidationException::withMessages([
        "row_" . ($index + 2) =>
        "Cannot mark Present. Employee {$employee->employee_code} has approved leave on {$attendanceDate->format('d-m-Y')}."
    ]);
}
            AttendanceProcessed::create([
                'employee_id' => $employee->id,
                'shift_id' => $shiftAssignment->shift_id,
                'date' => $attendanceDate,

                'check_in' => $checkIn,
                'check_out' => $checkOut,

                'working_hours' => $workingHours,
                'late_minutes' => $lateMinutes,
                'early_exit_minutes' => $earlyExitMinutes,

                'attendance_status' => $row['status'] ?? 'present',
                'remarks' => $row['remarks'] ?? null,
            ]);
        }
    }

    public function rules(): array
    {
        return [

            '*.employee_code' => [
                'required',
                'exists:employees,employee_code'
            ],

            '*.date' => [
                'required',
                'date_format:d/m/Y',
                'before_or_equal:today'
            ],

            '*.check_in' => [
                'required'
            ],

            '*.check_out' => [
                'required'
            ],

            '*.remarks' => [
                'nullable',
                'string'
            ],

            '*.status' => [
                'nullable',
                'string'
            ]
        ];
    }
}
