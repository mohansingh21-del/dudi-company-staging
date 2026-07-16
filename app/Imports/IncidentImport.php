<?php

namespace App\Imports;

use App\Models\Incident;
use App\Models\Shift;
use App\Models\IncidentType;
use App\Models\Site;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class IncidentImport implements ToCollection, WithHeadingRow
{
    protected $successCount = 0;
    protected $errors = [];

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            $rowArray = is_array($row) ? $row : (is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array)$row);
            $rowNum = $index + 2; // +1 for 0-based index offset, +1 for heading row

            // Check if the row is completely empty
            if (empty(array_filter($rowArray))) {
                continue;
            }

            // Extract row values
            $dateStr = isset($rowArray['incident_date']) ? trim((string)$rowArray['incident_date']) : '';
            $shiftName = isset($rowArray['shift_name']) ? trim((string)$rowArray['shift_name']) : '';
            $typeStr = isset($rowArray['incident_type']) ? trim((string)$rowArray['incident_type']) : '';
            $severity = isset($rowArray['severity']) ? strtoupper(trim((string)$rowArray['severity'])) : '';
            $siteName = isset($rowArray['site_name']) ? trim((string)$rowArray['site_name']) : (isset($rowArray['location_name']) ? trim((string)$rowArray['location_name']) : '');
            $categoryName = isset($rowArray['machine_category']) ? trim((string)$rowArray['machine_category']) : (isset($rowArray['equipment_category']) ? trim((string)$rowArray['equipment_category']) : '');
            $eqNameStr = isset($rowArray['machine_name']) ? trim((string)$rowArray['machine_name']) : (isset($rowArray['equipment_name']) ? trim((string)$rowArray['equipment_name']) : '');
            $empCode = isset($rowArray['employee_code']) ? trim((string)$rowArray['employee_code']) : '';
            $description = isset($rowArray['incident_description']) ? trim((string)$rowArray['incident_description']) : (isset($rowArray['description']) ? trim((string)$rowArray['description']) : '');
            $actionTaken = isset($rowArray['action_taken']) ? trim((string)$rowArray['action_taken']) : '';
            $preventiveMeasures = isset($rowArray['preventive_measures']) ? trim((string)$rowArray['preventive_measures']) : '';

            $rowErrors = [];

            // 1. Required field validations
            if ($dateStr === '') {
                $rowErrors[] = "Incident Date is required.";
            }
            if ($shiftName === '') {
                $rowErrors[] = "Shift Name is required.";
            }
            if ($typeStr === '') {
                $rowErrors[] = "Incident Type is required.";
            }
            if ($severity === '') {
                $rowErrors[] = "Severity is required.";
            }
            if ($siteName === '') {
                $rowErrors[] = "Site Name is required.";
            }
            if ($categoryName === '') {
                $rowErrors[] = "Machine Category is required.";
            }
            if ($eqNameStr === '') {
                $rowErrors[] = "Machine Name is required.";
            }
            if ($description === '') {
                $rowErrors[] = "Incident Description is required.";
            }
            if ($actionTaken === '') {
                $rowErrors[] = "Action Taken is required.";
            }

            // 2. Parse and validate date first
            $incidentDate = null;
            if ($dateStr !== '') {
                try {
                    $incidentDate = $this->parseDate($dateStr);
                    if ($incidentDate->isFuture()) {
                        $rowErrors[] = "Incident date cannot be a future date.";
                    }
                } catch (\Throwable $e) {
                    $rowErrors[] = "Date parsing error - " . $e->getMessage();
                }
            }

            // 3. Resolve entities
            $shift = null;
            if ($shiftName !== '') {
                $shift = Shift::where('shift_name', $shiftName)->first();
                if (!$shift) {
                    $rowErrors[] = "Shift with name '{$shiftName}' not found.";
                }
            }

            $incidentType = null;
            if ($typeStr !== '') {
                $incidentType = IncidentType::where('incident_type', $typeStr)
                    ->where('is_active', 1)
                    ->first();
                if (!$incidentType) {
                    $rowErrors[] = "Incident Type '{$typeStr}' not found or inactive.";
                }
            }

            if ($severity !== '' && !in_array($severity, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])) {
                $rowErrors[] = "Invalid severity '{$severity}'. Must be LOW, MEDIUM, HIGH, or CRITICAL.";
            }

            $location = null;
            if ($siteName !== '') {
                $location = Site::where('site_name', $siteName)->first();
                if (!$location) {
                    $rowErrors[] = "Site with name '{$siteName}' not found.";
                }
            }

            $equipment = null;
            if ($categoryName !== '') {
                $equipment = Equipment::where('name', $categoryName)->first();
                if (!$equipment) {
                    $rowErrors[] = "Machine Category '{$categoryName}' not found.";
                }
            }

            $equipmentName = null;
            if ($equipment && $eqNameStr !== '') {
                $equipmentName = EquipmentName::where('equipment_name', $eqNameStr)
                    ->where('equipment_id', $equipment->id)
                    ->first();
                if (!$equipmentName) {
                    $rowErrors[] = "Machine Name '{$eqNameStr}' not found under category '{$categoryName}'.";
                }
            }

            $employee = null;
            if ($empCode !== '') {
                $employee = Employee::where('employee_code', $empCode)->first();
                if (!$employee) {
                    $rowErrors[] = "Employee with code '{$empCode}' not found.";
                }
            }

            // 4. Resolve Shift Plan and validate equipment allocation if machine was provided
            $shiftPlan = null;
            if ($shift && $location && $incidentDate) {
                $shiftPlan = \App\Models\ShiftPlan::where('planning_date', $incidentDate->format('Y-m-d'))
                    ->where('shift_id', $shift->id)
                    ->where('site_id', $location->id)
                    ->first();
            }

            if ($equipmentName) {
                if (!$shiftPlan) {
                    $rowErrors[] = "No active shift plan found for date " . ($incidentDate ? $incidentDate->format('d/m/Y') : $dateStr) . ", shift '{$shiftName}', and site '{$siteName}'.";
                } else {
                    $allocated = \App\Models\ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlan->id)
                        ->where('equipment_name_id', $equipmentName->id)
                        ->exists();

                    if (!$allocated) {
                        $rowErrors[] = "Machine '{$eqNameStr}' is not assigned to the shift plan for date " . ($incidentDate ? $incidentDate->format('d/m/Y') : $dateStr) . ", shift '{$shiftName}', and site '{$siteName}'.";
                    }
                }
            }

            if (count($rowErrors) > 0) {
                foreach ($rowErrors as $errorMsg) {
                    $this->errors[] = "Row {$rowNum}: {$errorMsg}";
                }
                continue;
            }

            // 5. Save record
            try {
                DB::transaction(function () use ($incidentDate, $shift, $shiftPlan, $incidentType, $severity, $location, $equipment, $equipmentName, $employee, $description, $actionTaken, $preventiveMeasures) {
                    $lastIncident = Incident::orderBy('id', 'DESC')->first();
                    $sequence = 1;
                    if ($lastIncident) {
                        $parts = explode('-', $lastIncident->incident_no);
                        if (count($parts) === 3) {
                            $sequence = (int) $parts[2] + 1;
                        } else {
                            $sequence = Incident::count() + 1;
                        }
                    } else {
                        $sequence = Incident::count() + 1;
                    }
                    $incidentNo = 'INC-' . date('Y') . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);

                    Incident::create([
                        'incident_no'          => $incidentNo,
                        'incident_date'        => $incidentDate->format('Y-m-d'),
                        'shift_id'             => $shift->id,
                        'shift_plan_id'        => $shiftPlan ? $shiftPlan->id : null,
                        'incident_type_id'     => $incidentType->id,
                        'severity'             => $severity,
                        'location_id'          => $location->id,
                        'equipment_id'         => $equipment->id,
                        'equipment_name_id'    => $equipmentName->id,
                        'person_involved_id'   => $employee ? $employee->id : null,
                        'incident_description' => $description,
                        'action_taken'         => $actionTaken,
                        'preventive_measures'  => ($preventiveMeasures !== '') ? $preventiveMeasures : null,
                        'status'               => 'Under Review',
                    ]);

                    $this->successCount++;
                });
            } catch (\Throwable $th) {
                $this->errors[] = "Row {$rowNum}: Failed to save record - " . $th->getMessage();
            }
        }
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
