<?php

namespace App\Imports;

use App\Models\FuelEntry;
use App\Models\ShiftPlan;
use App\Models\Shift;
use App\Models\EquipmentName;
use App\Models\ShiftEquipmentAllocation;
use App\Models\Employee;
use App\Services\FuelService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class FuelImport implements ToCollection, WithHeadingRow
{
    protected $successCount = 0;
    protected $errors = [];
    protected $processedKeys = [];

    public function collection(Collection $rows)
    {
        $fuelService = resolve(FuelService::class);
        $this->processedKeys = [];

        foreach ($rows as $index => $row) {
            $rowArray = is_array($row) ? $row : (is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row);
            $rowNum = $index + 2; // +1 for 0-based index offset, +1 for heading row

            // Check if the row is completely empty
            if (empty(array_filter($rowArray))) {
                continue;
            }

            // Extract row values
            $shiftDateStr = isset($rowArray['shift_date']) ? trim((string) $rowArray['shift_date']) : '';
            $shiftName = isset($rowArray['shift_name']) ? trim((string) $rowArray['shift_name']) : '';
            $eqNameStr = isset($rowArray['equipment_name']) ? trim((string) $rowArray['equipment_name']) : '';
            $operatorCode = isset($rowArray['operator_code']) ? trim((string) $rowArray['operator_code']) : '';
            $fuelSourceStr = isset($rowArray['fuel_source']) ? trim((string) $rowArray['fuel_source']) : '';
            $openingFuelStr = isset($rowArray['opening_fuel']) ? trim((string) $rowArray['opening_fuel']) : '';
            $fuelIssuedStr = isset($rowArray['fuel_issued']) ? trim((string) $rowArray['fuel_issued']) : '';
            $closingFuelStr = isset($rowArray['closing_fuel']) ? trim((string) $rowArray['closing_fuel']) : '';
            $hoursMeterReadingStr = isset($rowArray['hours_meter_reading']) ? trim((string) $rowArray['hours_meter_reading']) : '';
            $kilometerReadingStr = isset($rowArray['kilometer_reading']) ? trim((string) $rowArray['kilometer_reading']) : '';
            $remarks = isset($rowArray['remarks']) ? trim((string) $rowArray['remarks']) : '';

            $rowErrors = [];

            // 1. Required field checks
            if ($shiftDateStr === '') {
                $rowErrors[] = "Shift Date is required.";
            }
            if ($shiftName === '') {
                $rowErrors[] = "Shift Name is required.";
            }
            if ($eqNameStr === '') {
                $rowErrors[] = "Equipment Name is required.";
            }
            if ($openingFuelStr === '') {
                $rowErrors[] = "Opening Fuel is required.";
            }
            if ($fuelIssuedStr === '') {
                $rowErrors[] = "Fuel Issued is required.";
            }

            // 2. Validate numeric constraints
            $openingFuel = null;
            if ($openingFuelStr !== '') {
                if (!is_numeric($openingFuelStr) || (float) $openingFuelStr < 0) {
                    $rowErrors[] = "Opening Fuel must be a numeric value greater than or equal to 0.";
                } else {
                    $openingFuel = (float) $openingFuelStr;
                }
            }

            $fuelIssued = null;
            if ($fuelIssuedStr !== '') {
                if (!is_numeric($fuelIssuedStr) || (float) $fuelIssuedStr <= 0) {
                    $rowErrors[] = "Fuel Issued must be a numeric value greater than 0.";
                } else {
                    $fuelIssued = (float) $fuelIssuedStr;
                }
            }

            $closingFuel = null;
            if ($closingFuelStr !== '') {
                if (!is_numeric($closingFuelStr) || (float) $closingFuelStr < 0) {
                    $rowErrors[] = "Closing Fuel must be a numeric value greater than or equal to 0.";
                } else {
                    $closingFuel = (float) $closingFuelStr;
                }
            }

            $hoursMeterReading = null;
            if ($hoursMeterReadingStr !== '') {
                if (!is_numeric($hoursMeterReadingStr) || (float) $hoursMeterReadingStr < 0) {
                    $rowErrors[] = "Hours Meter Reading must be a numeric value greater than or equal to 0.";
                } else {
                    $hoursMeterReading = (float) $hoursMeterReadingStr;
                }
            }

            $kilometerReading = null;
            if ($kilometerReadingStr !== '') {
                if (!is_numeric($kilometerReadingStr) || (float) $kilometerReadingStr < 0) {
                    $rowErrors[] = "Kilometer Reading must be a numeric value greater than or equal to 0.";
                } else {
                    $kilometerReading = (float) $kilometerReadingStr;
                }
            }

            // 3. Opening/Closing calculation constraints
            if ($openingFuel !== null && $fuelIssued !== null && $closingFuel !== null) {
                if ($closingFuel > ($openingFuel + $fuelIssued)) {
                    $rowErrors[] = "Closing fuel cannot be greater than opening fuel + fuel issued.";
                }
            }

            // 4. Parse date
            $shiftDate = null;
            if ($shiftDateStr !== '') {
                try {
                    $shiftDate = $this->parseDate($shiftDateStr);
                    if (!$shiftDate) {
                        $rowErrors[] = "Invalid Shift Date format.";
                    }
                } catch (\Throwable $e) {
                    $rowErrors[] = "Invalid Shift Date format - " . $e->getMessage();
                }
            }

            // 5. Resolve Shift
            $shift = null;
            if ($shiftName !== '') {
                $shift = Shift::where('shift_name', $shiftName)->first();
                if (!$shift) {
                    $rowErrors[] = "Shift with name '{$shiftName}' not found.";
                } elseif (!$shift->is_active) {
                    $rowErrors[] = "Shift '{$shiftName}' is inactive and cannot be used.";
                    $shift = null;
                }
            }

            // 6. Resolve Shift Plan
            $shiftPlan = null;
            if ($shiftDate && $shift) {
                $shiftPlan = ShiftPlan::whereDate('planning_date', $shiftDate->toDateString())
                    ->where('shift_id', $shift->id)
                    ->first();
                if (!$shiftPlan) {
                    $rowErrors[] = "Shift plan not found for the given date and shift.";
                } elseif (!in_array($shiftPlan->status, ['published', 'in_progress'])) {
                    $rowErrors[] = "Shift plan is not active or published.";
                }
            }

            // 7. Resolve Machine
            $equipmentName = null;
            if ($eqNameStr !== '') {
                $equipmentName = EquipmentName::where('equipment_name', $eqNameStr)->first();
                if (!$equipmentName) {
                    $rowErrors[] = "Machine '{$eqNameStr}' not found.";
                }
            }

            // 8. Resolve Machine Assignment
            $allocation = null;
            if ($shiftPlan && $equipmentName) {
                $allocation = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlan->id)
                    ->where('equipment_name_id', $equipmentName->id)
                    ->first();
                if (!$allocation) {
                    $rowErrors[] = "Machine is not assigned to current shift.";
                }
            }

            // 9. Resolve Operator
            $operatorUserId = null;
            if ($operatorCode !== '') {
                $employee = Employee::where('employee_code', $operatorCode)->first();
                if (!$employee) {
                    $rowErrors[] = "Operator with employee code '{$operatorCode}' not found.";
                } else {
                    $operatorUserId = $employee->roleUser ? $employee->roleUser->user_id : null;
                    if (!$operatorUserId) {
                        $rowErrors[] = "Operator does not have a user account.";
                    }
                }
            }

            // 10. Validate Reading Regressions
            if ($allocation) {
                if ($hoursMeterReading !== null) {
                    $previousHour = FuelEntry::forMachine($allocation->id)
                        ->where('status', 'active')
                        ->whereNotNull('hours_meter_reading')
                        ->orderBy('hours_meter_reading', 'desc')
                        ->first();
                    if ($previousHour && $hoursMeterReading < $previousHour->hours_meter_reading) {
                        $rowErrors[] = "Hour Meter Reading Cannot Be Less Than Previous Reading";
                    }
                }

                if ($kilometerReading !== null) {
                    $previousKm = FuelEntry::forMachine($allocation->id)
                        ->where('status', 'active')
                        ->whereNotNull('kilometer_reading')
                        ->orderBy('kilometer_reading', 'desc')
                        ->first();
                    if ($previousKm && $kilometerReading < $previousKm->kilometer_reading) {
                        $rowErrors[] = "Kilometer Reading Cannot Be Less Than Previous Reading";
                    }
                }
            }

            // 11. Normalize Fuel Source
            $normalizedSource = null;
            if ($fuelSourceStr !== '') {
                $normalizedSource = strtolower(str_replace(' ', '_', $fuelSourceStr));
                if (!in_array($normalizedSource, ['fuel_tanker', 'fuel_station', 'mobile_refueling_unit'])) {
                    $rowErrors[] = "Invalid Fuel Source. Must be fuel_tanker, fuel_station, or mobile_refueling_unit.";
                }
            }

            // 12. Validate Duplicate Entries
            if ($equipmentName && $shift && $shiftDate) {
                $formattedDate = $shiftDate->toDateString();
                $uniqueKey = sprintf(
                    '%d_%d_%s',
                    $equipmentName->id,
                    $shift->id,
                    $formattedDate
                );

                if (in_array($uniqueKey, $this->processedKeys)) {
                    $rowErrors[] = "Duplicate row found in the uploaded file for Equipment '{$eqNameStr}', Shift '{$shiftName}' on date {$formattedDate}.";
                } else {
                    $this->processedKeys[] = $uniqueKey;

                    $existingEntry = FuelEntry::where('equipment_name_id', $equipmentName->id)
                        ->where('shift_id', $shift->id)
                        ->where('fuel_log_date', $formattedDate)
                        ->where('status', '!=', 'cancelled')
                        ->first();

                    if ($existingEntry) {
                        $rowErrors[] = "Fuel entry already exists in database for Equipment '{$eqNameStr}' on Shift '{$shiftName}' on date {$formattedDate}.";
                    }
                }
            }

            if (count($rowErrors) > 0) {
                foreach ($rowErrors as $error) {
                    $this->errors[] = "Row {$rowNum}: {$error}";
                }
            } else {
                try {
                    $data = [
                        'shift_plan_id' => $shiftPlan->id,
                        'fuel_log_date' => $shiftDate->toDateString(),
                        'shift_id' => $shift->id,
                        'equipment_allocation_id' => $allocation->id,
                        'equipment_id' => $allocation->equipmentName->equipment_id,
                        'equipment_name_id' => $allocation->equipmentName->id,
                        'operator_id' => $operatorUserId,
                        'fuel_source' => $normalizedSource ?: null,
                        'opening_fuel' => $openingFuel,
                        'fuel_issued' => $fuelIssued,
                        'closing_fuel' => $closingFuel,
                        'hours_meter_reading' => $hoursMeterReading,
                        'kilometer_reading' => $kilometerReading,
                        'remarks' => $remarks ?: null,
                    ];

                    $fuelService->createEntry($data, auth()->id() ?? 1);
                    $this->successCount++;
                } catch (\Throwable $th) {
                    $this->errors[] = "Row {$rowNum}: Failed to save record - " . $th->getMessage();
                }
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
            $formats = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'];
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
