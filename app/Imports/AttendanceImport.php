<?php

namespace App\Imports;

use App\Models\AttendanceProcessed;
use App\Models\Employee;
use App\Models\EmployeePayroll;
use App\Models\LeaveType;
use App\Services\AttendanceLeaveSync;
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
            $statusRaw = trim((string) ($row['status'] ?? ''));

            $status = strtolower($statusRaw);
            $status = str_replace(['-', '_'], ' ', $status);
            $status = preg_replace('/\s+/', ' ', $status);

            // A blank status column still means an ordinary working day, as it
            // always has.
            if ($status === '') {
                $status = 'present';
            }

            // Keys are matched after the normalisation above, which folds case
            // and turns '-' and '_' into single spaces: 'Half-Day', 'half_day'
            // and 'HALF DAY' all arrive here as 'half day'. The shorthands are
            // the ones site clerks actually write into the sheet.
            $statusMap = [
                'present'    => 'present',
                'p'          => 'present',

                'absent'     => 'absent',
                'a'          => 'absent',

                'half day'   => 'half_day',
                'halfday'    => 'half_day',
                'half'       => 'half_day',
                'hd'         => 'half_day',

                'leave'      => 'leave',
                'l'          => 'leave',

                'rest day'   => 'rest_day',
                'restday'    => 'rest_day',
                'rest'       => 'rest_day',
                'weekly off' => 'rest_day',
                'week off'   => 'rest_day',
                'weekoff'    => 'rest_day',
                'off'        => 'rest_day',
                'wo'         => 'rest_day',
                'w/o'        => 'rest_day',
            ];

            // An unrecognised status used to fall straight through to the
            // 'present' default of the insert switch below, so a typo or an
            // unknown shorthand became a paid working day with no check-in and
            // no error. Reject the row instead.
            if (!isset($statusMap[$status])) {
                $errors["row_{$rowNumber}"][] =
                    "Unknown status \"{$statusRaw}\". Use one of: Present, Absent, Half Day, Leave, Rest Day.";
                continue;
            }

            $status = $statusMap[$status];

            /*
            |--------------------------------------------------------------------------
            | LEAVE TYPE (only for 'Leave' rows)
            |--------------------------------------------------------------------------
            |
            | A 'Leave' day has to be booked against a Form E block so the leave
            | register and payroll can see it. The 'leave_type' column carries
            | the block name; a blank or unknown value rejects the row rather
            | than saving a day that counts toward nothing.
            |
            | 'Rest Day' as a status needs no column. 'Leave' + a Compensatory
            | Rest type is accepted too and folded to a rest day — the two
            | spellings mean the same thing.
            */
            $leaveTypeId = null;

            if ($status === 'leave') {
                $leaveTypeRaw = trim((string) ($row['leave_type'] ?? ''));

                if ($leaveTypeRaw === '') {
                    $errors["row_{$rowNumber}"][] =
                        'Leave type is required for a Leave row. '
                        . 'Use one of: ' . $this->leaveTypeNameList() . ', Compensatory Rest.';
                    continue;
                }

                $leaveType = $this->resolveLeaveType($leaveTypeRaw);

                if (!$leaveType) {
                    $errors["row_{$rowNumber}"][] =
                        "Unknown leave type \"{$leaveTypeRaw}\". Use one of: "
                        . $this->leaveTypeNameList() . ', Compensatory Rest.';
                    continue;
                }

                $leaveTypeId = $leaveType->id;
            }

            // 'Leave' + Compensatory Rest is really a rest day: store it as one,
            // let it draw on the rest-day cap, and drop the leave type.
            [$status, $leaveTypeId] = AttendanceLeaveSync::normalize($status, $leaveTypeId);

            // Quota / cap / clash gates for anything that becomes a Leave row —
            // the same checks the Leave apply form runs.
            if (in_array($status, AttendanceLeaveSync::LEAVE_STATUSES, true)) {
                try {
                    $syncType = AttendanceLeaveSync::resolveLeaveType($status, $leaveTypeId);

                    $blockMessage = AttendanceLeaveSync::blockMessage(
                        $employee->id,
                        $date->format('Y-m-d'),
                        $syncType
                    );

                    if ($blockMessage) {
                        $errors["row_{$rowNumber}"][] = $blockMessage;
                        continue;
                    }
                } catch (\InvalidArgumentException $e) {
                    $errors["row_{$rowNumber}"][] = $e->getMessage();
                    continue;
                }
            }

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

            /*
            |--------------------------------------------------------------------------
            | NIGHT SHIFT: ROLL CHECK-OUT PAST MIDNIGHT
            |--------------------------------------------------------------------------
            |
            | The sheet carries one date per row, so parseTime() pins both times
            | to it. A 22:00 to 06:00 night shift therefore arrives with a
            | check-out that sits before its own check-in; the check-out really
            | belongs to the next day.
            */
            if ($checkInTime && $checkOutTime) {

                if ($checkOutTime->equalTo($checkInTime)) {
                    $errors["row_{$rowNumber}"][] = "Check-out time cannot equal check-in time.";
                    continue;
                }

                $checkOutTime = \App\Services\ShiftRosterResolver::resolveCheckOut(
                    $checkInTime,
                    $checkOutTime
                );

                $spanHours = $checkInTime->diffInMinutes($checkOutTime) / 60;

                if ($spanHours > \App\Services\ShiftRosterResolver::MAX_ATTENDANCE_SPAN_HOURS) {
                    $errors["row_{$rowNumber}"][] =
                        "Check-in to check-out spans " . round($spanHours, 2) . " hours, which exceeds the "
                        . \App\Services\ShiftRosterResolver::MAX_ATTENDANCE_SPAN_HOURS . " hour limit.";
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
                'leave_type_id' => $leaveTypeId,
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

            // Already one of the attendance_status enum values: step 1 rejects
            // every row whose status the map does not recognise.
            $attendanceStatus = $status;

            AttendanceProcessed::create([
                'employee_id' => $employee->id,
                'date' => $date->format('Y-m-d'),
                // Imported rows carry no location, so the day inherits the
                // worker's standing assignment; a correction can override it.
                'place_of_work' => $employee->place_of_employment,
                'check_in' => $checkIn ? $checkIn->toDateTimeString() : null,
                'check_out' => $checkOut ? $checkOut->toDateTimeString() : null,
                'working_hours' => $workingHours,
                'late_minutes' => 0,
                'early_exit_minutes' => 0,
                'attendance_status' => $attendanceStatus,
                'remarks' => $item['remarks'],
            ]);

            // A leave/rest_day row also gets its backing Leave record so the
            // Form E register and payroll read one source.
            if (in_array($attendanceStatus, AttendanceLeaveSync::LEAVE_STATUSES, true)) {
                AttendanceLeaveSync::sync(
                    $employee->id,
                    $date->format('Y-m-d'),
                    $attendanceStatus,
                    $item['leave_type_id']
                );
            }
        }
    }

    /**
     * Match a leave type named in the sheet to a Form E block. Accepts the block
     * name ('Earned Leave'), the register_group key ('earned') or its label.
     */
    private function resolveLeaveType(string $raw): ?LeaveType
    {
        $needle = strtolower(trim($raw));

        return LeaveType::onRegister()->get()->first(function (LeaveType $type) use ($needle) {
            return $needle === strtolower((string) $type->name)
                || $needle === strtolower((string) $type->register_group)
                || $needle === strtolower((string) $type->register_group_label);
        });
    }

    /** Human list of the leave blocks a sheet may name, for error messages. */
    private function leaveTypeNameList(): string
    {
        return LeaveType::onRegister()
            ->where('register_group', '!=', 'compensatory_rest')
            ->pluck('name')
            ->implode(', ');
    }

    public function rules(): array
    {
        return [
            '*.employee_code' => ['required', 'exists:employees,employee_code'],
            '*.date' => ['required'],
            '*.check_in' => ['nullable'],
            '*.check_out' => ['nullable'],
            '*.status' => ['nullable'],
            '*.leave_type' => ['nullable'],
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