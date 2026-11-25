<?php

namespace App\Imports;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EmployeeShiftAssignmentImport implements ToCollection, WithHeadingRow
{
    protected $successCount = 0;
    protected $errors = [];
    protected $warnings = [];

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowArray = $row->toArray();
            $keys = array_keys($rowArray);

            // Try explicit employee_code and shift_code keys first
            $empCode = isset($rowArray['employee_code']) ? trim((string) $rowArray['employee_code']) : '';
            if ($empCode === '') {
                // Fallback to fuzzy key matching
                foreach ($keys as $k) {
                    if (stripos((string) $k, 'code') !== false || stripos((string) $k, 'employee') !== false) {
                        $empCode = trim((string) ($rowArray[$k] ?? ''));
                        break;
                    }
                }
            }

            $shiftName = isset($rowArray['shift_code']) ? trim((string) $rowArray['shift_code']) : '';
            if ($shiftName === '') {
                // Fallback to fuzzy key matching
                foreach ($keys as $k) {
                    if (stripos((string) $k, 'shift') !== false) {
                        $shiftName = trim((string) ($rowArray[$k] ?? ''));
                        break;
                    }
                }
            }

            // Row number for error reporting (1-indexed; +2 because of heading row)
            $rowNum = $index + 2;

            if ($empCode === '' && $shiftName === '') {
                continue; // Skip empty rows
            }

            if ($empCode === '') {
                $this->errors[] = "Row {$rowNum}: Employee code is empty.";
                continue;
            }

            if ($shiftName === '') {
                $this->errors[] = "Row {$rowNum}: Shift name is empty.";
                continue;
            }

            // Find employee by employee_code
            $employee = Employee::with('relay')->where('employee_code', $empCode)->first();

            if (!$employee) {
                $this->errors[] = "Row {$rowNum}: Employee with code '{$empCode}' not found.";
                continue;
            }

            if (!$employee->relay_id || !$employee->relay || !$employee->relay->is_rotating) {
                $this->errors[] = "Row {$rowNum}: Shift assignment is not allowed for non-rotating/general shift employee '{$employee->name}'.";
                continue;
            }

            // Find shift by shift_name
            $shift = Shift::where('shift_name', $shiftName)->first();

            if (!$shift) {
                $this->errors[] = "Row {$rowNum}: Shift with name '{$shiftName}' not found.";
                continue;
            }

            if (!$shift->is_active) {
                $this->errors[] = "Row {$rowNum}: Shift '{$shiftName}' is inactive and cannot be assigned.";
                continue;
            }

            // Check if employee already has a shift assignment
            $existingAssignment = EmployeeShiftAssignment::with('shift')->where('employee_id', $employee->id)->first();
            if ($existingAssignment) {
                $existingShiftName = optional($existingAssignment->shift)->shift_name ?? 'Unknown Shift';
                $this->errors[] = "Row {$rowNum}: Employee '{$employee->name}' already has shift '{$existingShiftName}' assigned.";
                continue;
            }

            // Assign shift
            EmployeeShiftAssignment::create([
                'employee_id' => $employee->id,
                'shift_id' => $shift->id
            ]);

            $this->successCount++;
        }
    }

    public function getSuccessCount()
    {
        return $this->successCount;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function getWarnings()
    {
        return $this->warnings;
    }
}
