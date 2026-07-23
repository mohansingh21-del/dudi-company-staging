<?php

namespace App\Imports;

use App\Models\BreakdownTicket;
use App\Models\Shift;
use App\Models\Employee;
use App\Models\EquipmentName;
use App\Models\BreakdownType;
use App\Models\ShiftPlan;
use App\Models\ShiftEquipmentAllocation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class BreakdownImport implements ToCollection, WithHeadingRow
{
    protected $successCount = 0;
    protected $errors = [];
    protected $processedKeys = [];

    public function collection(Collection $rows)
    {
        $year = date('Y');
        $this->processedKeys = [];

        foreach ($rows as $index => $row) {
            $rowArray = is_array($row) ? $row : (is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array)$row);
            $rowNum = $index + 2; // +1 for 0-based index offset, +1 for heading row

            // Check if the row is completely empty
            if (empty(array_filter($rowArray))) {
                continue;
            }

            // Extract row values
            $dateTimeStr = isset($rowArray['breakdown_date_time']) ? trim((string)$rowArray['breakdown_date_time']) : '';
            $shiftName = isset($rowArray['shift_name']) ? trim((string)$rowArray['shift_name']) : '';
            $empCode = isset($rowArray['employee_code']) ? trim((string)$rowArray['employee_code']) : '';
            $eqNameStr = isset($rowArray['equipment_name']) ? trim((string)$rowArray['equipment_name']) : '';
            $typeStr = isset($rowArray['breakdown_type']) ? trim((string)$rowArray['breakdown_type']) : '';
            $severity = isset($rowArray['severity']) ? strtoupper(trim((string)$rowArray['severity'])) : '';
            $description = isset($rowArray['description']) ? trim((string)$rowArray['description']) : '';
            $downtimeStartStr = isset($rowArray['repair_start_time']) ? trim((string)$rowArray['repair_start_time']) : (isset($rowArray['downtime_start']) ? trim((string)$rowArray['downtime_start']) : '');
            $downtimeEndStr = isset($rowArray['repair_end_time']) ? trim((string)$rowArray['repair_end_time']) : (isset($rowArray['downtime_end']) ? trim((string)$rowArray['downtime_end']) : '');
            $resolutionNotes = isset($rowArray['action_taken']) ? trim((string)$rowArray['action_taken']) : (isset($rowArray['resolution_notes']) ? trim((string)$rowArray['resolution_notes']) : '');

            $rowErrors = [];

            // 1. Validate required fields
            if ($dateTimeStr === '') {
                $rowErrors[] = "Breakdown date time is required.";
            }
            if ($shiftName === '') {
                $rowErrors[] = "Shift name is required.";
            }
            if ($empCode === '') {
                $rowErrors[] = "Employee code is required.";
            }
            if ($eqNameStr === '') {
                $rowErrors[] = "Equipment name is required.";
            }
            if ($typeStr === '') {
                $rowErrors[] = "Breakdown type is required.";
            }
            if ($severity === '') {
                $rowErrors[] = "Severity is required.";
            }

            // Validate that times are not 00:00:00 or 00:00
            if ($downtimeStartStr !== '' && $this->isZeroTime($downtimeStartStr)) {
                $rowErrors[] = "Repair Start Time cannot be 00:00:00 or 00:00.";
            }
            if ($downtimeEndStr !== '' && $this->isZeroTime($downtimeEndStr)) {
                $rowErrors[] = "Repair End Time cannot be 00:00:00 or 00:00.";
            }

            // 2. Resolve entities
            $shift = null;
            if ($shiftName !== '') {
                $shift = Shift::where('shift_name', $shiftName)->first();
                if (!$shift) {
                    $rowErrors[] = "Shift with name '{$shiftName}' not found.";
                }
            }

            $employee = null;
            if ($empCode !== '') {
                $employee = Employee::where('employee_code', $empCode)->first();
                if (!$employee) {
                    $rowErrors[] = "Employee with code '{$empCode}' not found.";
                }
            }

            $eqName = null;
            if ($eqNameStr !== '') {
                $eqName = EquipmentName::where('equipment_name', $eqNameStr)->first();
                if (!$eqName) {
                    $rowErrors[] = "Equipment Name '{$eqNameStr}' not found.";
                }
            }

            $breakdownType = null;
            if ($typeStr !== '') {
                $breakdownType = BreakdownType::where('breakdown_type', $typeStr)->first();
                if (!$breakdownType) {
                    $rowErrors[] = "Breakdown Type '{$typeStr}' not found.";
                }
            }

            if ($severity !== '' && !in_array($severity, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])) {
                $rowErrors[] = "Invalid severity '{$severity}'. Must be LOW, MEDIUM, HIGH, or CRITICAL.";
            }

            // 3. Parse and validate dates
            $breakdownDateTime = null;
            $downtimeStart = null;
            $downtimeEnd = null;
            if ($dateTimeStr !== '') {
                try {
                    $breakdownDateTime = $this->parseDate($dateTimeStr);
                    if ($downtimeStartStr !== '') {
                        $startCarbon = $this->parseTime($downtimeStartStr);
                        if ($startCarbon->year < 2000 || !$this->hasDateSeparator($downtimeStartStr)) {
                            $downtimeStart = Carbon::parse($breakdownDateTime->toDateString() . ' ' . $startCarbon->format('H:i:s'));
                        } else {
                            $downtimeStart = $startCarbon;
                        }
                    }
                    if ($downtimeEndStr !== '') {
                        $endCarbon = $this->parseTime($downtimeEndStr);
                        if ($endCarbon->year < 2000 || !$this->hasDateSeparator($downtimeEndStr)) {
                            $downtimeEnd = Carbon::parse($breakdownDateTime->toDateString() . ' ' . $endCarbon->format('H:i:s'));
                        } else {
                            $downtimeEnd = $endCarbon;
                        }
                    }

                    if ($downtimeStart && $breakdownDateTime->format('H:i:s') === '00:00:00') {
                        $breakdownDateTime = $downtimeStart->copy();
                    }
                } catch (\Throwable $e) {
                    $rowErrors[] = "Date parsing error - " . $e->getMessage();
                }
            }

            if ($downtimeEnd && !$downtimeStart) {
                $rowErrors[] = "Repair Start Time is required when Repair End Time is provided.";
            }

            if ($downtimeStart && $downtimeEnd && $downtimeEnd->lte($downtimeStart)) {
                $rowErrors[] = "Repair End Time must be after Repair Start Time.";
            }

            // 4. Resolve equipment allocation
            $allocationId = null;
            if ($shift && $breakdownDateTime && $eqName) {
                $ticketDate = $breakdownDateTime->toDateString();
                $shiftPlan = ShiftPlan::where('shift_id', $shift->id)
                    ->where('planning_date', $ticketDate)
                    ->first();
                if (!$shiftPlan) {
                    $rowErrors[] = "Shift plan not found for date {$ticketDate} and shift {$shiftName}.";
                } else {
                    $allocation = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlan->id)
                        ->where('equipment_name_id', $eqName->id)
                        ->first();
                    if (!$allocation) {
                        $rowErrors[] = "Machine '{$eqNameStr}' is not assigned in that shift plan.";
                    } else {
                        $allocationId = $allocation->id;
                    }
                }
            }

            // 4.5 Validate Duplicate Entries
            if ($eqName && $shift && $breakdownDateTime && $breakdownType) {
                $formattedDateTime = $breakdownDateTime->format('H:i:s') === '00:00:00' 
                    ? $breakdownDateTime->format('Y-m-d') 
                    : $breakdownDateTime->format('Y-m-d H:i:s');
                
                $uniqueKey = sprintf(
                    '%d_%d_%s_%d',
                    $eqName->id,
                    $shift->id,
                    $breakdownDateTime->format('Y-m-d H:i:s'),
                    $breakdownType->id
                );

                if (in_array($uniqueKey, $this->processedKeys)) {
                    $rowErrors[] = "Duplicate row found in the uploaded file for Equipment '{$eqNameStr}', Shift '{$shiftName}' at {$formattedDateTime}.";
                } else {
                    $this->processedKeys[] = $uniqueKey;

                    $existingTicket = BreakdownTicket::where('equipment_name_id', $eqName->id)
                        ->where('shift_id', $shift->id)
                        ->where('breakdown_date_time', $breakdownDateTime->format('Y-m-d H:i:s'))
                        ->where('breakdown_type_id', $breakdownType->id)
                        ->first();

                    if ($existingTicket) {
                        $rowErrors[] = "Breakdown ticket '{$existingTicket->ticket_number}' already exists in database for Equipment '{$eqNameStr}' on Shift '{$shiftName}' at {$formattedDateTime}.";
                    }
                }
            }

            // Report all errors for this row at once, and proceed to next row
            if (count($rowErrors) > 0) {
                foreach ($rowErrors as $errorMsg) {
                    $this->errors[] = "Row {$rowNum}: {$errorMsg}";
                }
                continue;
            }

            // 5. Database transaction to generate ticket number sequence and insert record
            try {
                DB::transaction(function () use ($shift, $employee, $eqName, $breakdownType, $severity, $description, $breakdownDateTime, $downtimeStart, $downtimeEnd, $resolutionNotes, $allocationId, $year) {
                    $lastTicket = BreakdownTicket::whereYear('created_at', $year)
                        ->orderBy('id', 'DESC')
                        ->first();

                    $sequence = 1;
                    if ($lastTicket) {
                        $parts = explode('-', $lastTicket->ticket_number);
                        if (count($parts) === 3) {
                            $sequence = (int) $parts[2] + 1;
                        }
                    }
                    $ticketNumber = 'BRK-' . $year . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);

                    $data = [
                        'ticket_number'           => $ticketNumber,
                        'shift_id'                => $shift->id,
                        'reported_by'             => $employee->id,
                        'equipment_id'            => $eqName->equipment_id,
                        'equipment_name_id'       => $eqName->id,
                        'equipment_allocation_id' => $allocationId,
                        'breakdown_type_id'       => $breakdownType->id,
                        'severity'                => $severity,
                        'description'             => ($description !== '') ? $description : 'Imported breakdown record.',
                        'breakdown_date_time'     => $breakdownDateTime,
                        'downtime_start'          => $downtimeStart,
                    ];

                    if ($downtimeEnd) {
                        $data['downtime_end']     = $downtimeEnd;
                        $data['downtime_minutes'] = $downtimeEnd->diffInMinutes($downtimeStart);
                        $data['status']           = 'closed';
                        $data['resolved_by']      = auth()->id() ?? $employee->id;
                        $data['resolved_at']      = now();
                        $data['resolution_notes'] = ($resolutionNotes !== '') ? $resolutionNotes : null;
                    } else {
                        $data['status']           = 'open';
                    }

                    BreakdownTicket::create($data);
                    $this->successCount++;
                });
            } catch (\Throwable $th) {
                $this->errors[] = "Row {$rowNum}: Failed to save record - " . $th->getMessage();
            }
        }
    }

    private function isZeroTime(string $timeStr): bool
    {
        if (empty($timeStr)) {
            return false;
        }
        try {
            $timeCarbon = $this->parseTime($timeStr);
            if (!$timeCarbon) {
                return false;
            }
            return $timeCarbon->format('H:i:s') === '00:00:00';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function parseTime($value)
    {
        if (empty($value)) {
            return null;
        }
        if (is_numeric($value)) {
            // Excel time is a fraction of a 24-hour day (e.g. 0.5 = 12:00 PM)
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value));
        }
        
        $value = trim($value);
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            // Try different standard formats
            $formats = ['H:i:s', 'H:i', 'h:i:s A', 'h:i A', 'g:i A', 'g:i:s A'];
            foreach ($formats as $format) {
                try {
                    return Carbon::createFromFormat($format, $value);
                } catch (\Throwable $ex) {
                    continue;
                }
            }
            throw new \Exception("Could not parse time: {$value}");
        }
    }

    private function hasDateSeparator(string $str): bool
    {
        return strpos($str, '-') !== false || strpos($str, '/') !== false;
    }

    private function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value));
        }

        $value = trim($value);
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            $formats = ['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'];
            foreach ($formats as $format) {
                try {
                    return Carbon::createFromFormat($format, $value);
                } catch (\Throwable $ex) {
                    continue;
                }
            }
            throw new \Exception("Could not parse date: {$value}");
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
}
