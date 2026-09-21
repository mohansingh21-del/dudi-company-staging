<?php

namespace App\Imports;

use App\Models\DispatchTrip;
use App\Models\ShiftPlan;
use App\Models\Shift;
use App\Models\Site;
use App\Models\EquipmentName;
use App\Models\Employee;
use App\Models\SitePoint;
use App\Models\ShiftEquipmentAllocation;
use App\Services\DispatchTripService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class DispatchImport implements ToCollection, WithHeadingRow
{
    protected $successCount = 0;
    protected $errors = [];
    protected $processedKeys = [];

    public function collection(Collection $rows)
    {
        $dispatchService = resolve(DispatchTripService::class);
        $this->processedKeys = [];

        foreach ($rows as $index => $row) {
            $rowArray = is_array($row) ? $row : (is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array)$row);
            $rowNum = $index + 2; // +1 for 0-based index offset, +1 for heading row

            // Check if the row is completely empty
            if (empty(array_filter($rowArray))) {
                continue;
            }

            // Extract row values (supporting variations in headers)
            $shiftDateStr = isset($rowArray['shift_date']) ? trim((string)$rowArray['shift_date']) : '';
            $shiftName = isset($rowArray['shift_name']) ? trim((string)$rowArray['shift_name']) : '';
            $dumperName = isset($rowArray['dumper_name']) ? trim((string)$rowArray['dumper_name']) : (isset($rowArray['dumper_equipment_name']) ? trim((string)$rowArray['dumper_equipment_name']) : '');
            
            // Driver/operator code
            $driverCode = '';
            if (isset($rowArray['driver_code'])) {
                $driverCode = trim((string)$rowArray['driver_code']);
            } elseif (isset($rowArray['operator_code'])) {
                $driverCode = trim((string)$rowArray['operator_code']);
            }

            $excavatorName = isset($rowArray['excavator_name']) ? trim((string)$rowArray['excavator_name']) : (isset($rowArray['excavator_equipment_name']) ? trim((string)$rowArray['excavator_equipment_name']) : '');
            $loadingPointName = isset($rowArray['loading_point']) ? trim((string)$rowArray['loading_point']) : (isset($rowArray['loading_point_name']) ? trim((string)$rowArray['loading_point_name']) : '');
            $dumpingPointName = isset($rowArray['dumping_point']) ? trim((string)$rowArray['dumping_point']) : (isset($rowArray['dumping_point_name']) ? trim((string)$rowArray['dumping_point_name']) : '');
            $startTimeStr = isset($rowArray['start_time']) ? trim((string)$rowArray['start_time']) : '';
            $endTimeStr = isset($rowArray['end_time']) ? trim((string)$rowArray['end_time']) : '';
            $quantityBcmStr = isset($rowArray['quantity_bcm']) ? trim((string)$rowArray['quantity_bcm']) : '';
            $distanceMetersStr = isset($rowArray['distance']) ? trim((string)$rowArray['distance']) : (isset($rowArray['distance_meters']) ? trim((string)$rowArray['distance_meters']) : '');
            $totalCyclesStr = isset($rowArray['total_cycles']) ? trim((string)$rowArray['total_cycles']) : '';

            $rowErrors = [];

            // 1. Required field presence validation
            if ($shiftDateStr === '') {
                $rowErrors[] = "Shift Date is required.";
            }
            if ($shiftName === '') {
                $rowErrors[] = "Shift Name is required.";
            }
            if ($dumperName === '') {
                $rowErrors[] = "Dumper Name is required.";
            }
            if ($driverCode === '') {
                $rowErrors[] = "Driver Code is required.";
            }
            if ($loadingPointName === '') {
                $rowErrors[] = "Loading Point is required.";
            }
            if ($dumpingPointName === '') {
                $rowErrors[] = "Dumping Point is required.";
            }
            if ($startTimeStr === '') {
                $rowErrors[] = "Start Time is required.";
            }
            if ($endTimeStr === '') {
                $rowErrors[] = "End Time is required.";
            }
            if ($quantityBcmStr === '') {
                $rowErrors[] = "Quantity BCM is required.";
            }
            if ($totalCyclesStr === '') {
                $rowErrors[] = "Total Cycles is required.";
            }

            // 2. Reject zero times (00:00:00 or 00:00)
            if ($startTimeStr !== '' && $this->isZeroTime($startTimeStr)) {
                $rowErrors[] = "Start Time cannot be 00:00:00 or 00:00.";
            }
            if ($endTimeStr !== '' && $this->isZeroTime($endTimeStr)) {
                $rowErrors[] = "End Time cannot be 00:00:00 or 00:00.";
            }

            // 3. Numeric constraints validation
            $quantityBcm = null;
            if ($quantityBcmStr !== '') {
                if (!is_numeric($quantityBcmStr) || (float)$quantityBcmStr <= 0) {
                    $rowErrors[] = "Quantity BCM must be a numeric value greater than 0.";
                } else {
                    $quantityBcm = (float)$quantityBcmStr;
                }
            }

            $totalCycles = null;
            if ($totalCyclesStr !== '') {
                if (!filter_var($totalCyclesStr, FILTER_VALIDATE_INT) || (int)$totalCyclesStr <= 0) {
                    $rowErrors[] = "Total Cycles must be an integer value greater than 0.";
                } else {
                    $totalCycles = (int)$totalCyclesStr;
                }
            }

            $distanceMeters = null;
            if ($distanceMetersStr !== '') {
                if (!is_numeric($distanceMetersStr) || (float)$distanceMetersStr <= 0) {
                    $rowErrors[] = "Distance must be a numeric value greater than 0.";
                } else {
                    $distanceMeters = (float)$distanceMetersStr;
                }
            }

            // 4. Parse date and times
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

            $startTimeCarbon = null;
            if ($startTimeStr !== '' && !$this->isZeroTime($startTimeStr)) {
                try {
                    $startTimeCarbon = $this->parseTime($startTimeStr);
                    if (!$startTimeCarbon) {
                        $rowErrors[] = "Invalid Start Time format.";
                    }
                } catch (\Throwable $e) {
                    $rowErrors[] = "Invalid Start Time format - " . $e->getMessage();
                }
            }

            $endTimeCarbon = null;
            if ($endTimeStr !== '' && !$this->isZeroTime($endTimeStr)) {
                try {
                    $endTimeCarbon = $this->parseTime($endTimeStr);
                    if (!$endTimeCarbon) {
                        $rowErrors[] = "Invalid End Time format.";
                    }
                } catch (\Throwable $e) {
                    $rowErrors[] = "Invalid End Time format - " . $e->getMessage();
                }
            }

            // 5. Resolve Shift, Site, and ShiftPlan
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

            $shiftPlan = null;
            if ($shiftDate && $shift) {
                $shiftPlan = ShiftPlan::whereDate('planning_date', $shiftDate->toDateString())
                    ->where('shift_id', $shift->id)
                    ->first();
                
                if (!$shiftPlan) {
                    $rowErrors[] = "No active shift plan found for the selected shift and date.";
                } elseif (!in_array($shiftPlan->status, ['published', 'in_progress'])) {
                    $rowErrors[] = "Shift plan is not active or published.";
                }
            }

            $site = null;
            if ($shiftPlan) {
                $site = $shiftPlan->site;
                if (!$site) {
                    $rowErrors[] = "Site not found for the resolved shift plan.";
                }
            }

            // 6. Resolve Dumper
            $dumper = null;
            if ($dumperName !== '') {
                $dumper = EquipmentName::where('equipment_name', $dumperName)->first();
                if (!$dumper) {
                    $rowErrors[] = "Dumper with name '{$dumperName}' not found.";
                }
            }

            // 7. Resolve Excavator
            $excavator = null;
            if ($excavatorName !== '') {
                $excavator = EquipmentName::where('equipment_name', $excavatorName)->first();
                if (!$excavator) {
                    $rowErrors[] = "Excavator with name '{$excavatorName}' not found.";
                }
            }

            // 8. Resolve Driver
            $driver = null;
            if ($driverCode !== '') {
                $driver = Employee::where('employee_code', $driverCode)->first();
                if (!$driver) {
                    $rowErrors[] = "Driver with employee code '{$driverCode}' not found.";
                }
            }

            // 9. Resolve Loading and Dumping Points
            $loadingPoint = null;
            if ($site && $loadingPointName !== '') {
                $loadingPoint = SitePoint::where('site_id', $site->id)
                    ->where('name', $loadingPointName)
                    ->where('type', 'loading')
                    ->where('is_active', true)
                    ->first();
                if (!$loadingPoint) {
                    $rowErrors[] = "Selected Loading Point Is Invalid or Not Mapped To Current Site.";
                }
            }

            $dumpingPoint = null;
            if ($site && $dumpingPointName !== '') {
                $dumpingPoint = SitePoint::where('site_id', $site->id)
                    ->where('name', $dumpingPointName)
                    ->where('type', 'dumping')
                    ->where('is_active', true)
                    ->first();
                if (!$dumpingPoint) {
                    $rowErrors[] = "Selected Dumping Point Is Invalid or Not Mapped To Current Site.";
                }
            }

            // 10. Allocations & Mapping Validation
            if ($shiftPlan) {
                // Validate Dumper allocation
                $dumperAllocation = null;
                if ($dumper) {
                    $dumperAllocation = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlan->id)
                        ->where('equipment_name_id', $dumper->id)
                        ->first();
                    if (!$dumperAllocation) {
                        $rowErrors[] = "Selected Dumper Is Not Assigned To Current Shift.";
                    } else {
                        // Check if Excavator name matches parent of dumper
                        if ($excavator && $dumperAllocation->parent_equipment_id != $excavator->id) {
                            $rowErrors[] = "Selected Excavator Is Not Mapped To Selected Dumper In Shift Allocation.";
                        } elseif (!$excavator && $dumperAllocation->parent_equipment_id) {
                            // Auto resolve excavator from allocation if not explicitly specified
                            $excavator = EquipmentName::find($dumperAllocation->parent_equipment_id);
                        }
                    }
                }

                // Validate Excavator allocation
                if ($excavator) {
                    $excavatorAllocated = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlan->id)
                        ->where('equipment_name_id', $excavator->id)
                        ->exists();
                    if (!$excavatorAllocated) {
                        $rowErrors[] = "Selected Excavator Is Not Assigned To Current Shift.";
                    }
                }

                // 11. Validate time ordering using resolved full datetimes
                if ($startTimeCarbon && $endTimeCarbon) {
                    $resolvedStart = DispatchTripService::resolveDateTimeFromTime($startTimeCarbon->format('H:i:s'), $shiftPlan);
                    $resolvedEnd = DispatchTripService::resolveDateTimeFromTime($endTimeCarbon->format('H:i:s'), $shiftPlan);
                    
                    if (Carbon::parse($resolvedEnd)->lte(Carbon::parse($resolvedStart))) {
                        $rowErrors[] = "The end time must be after the start time.";
                    }
                }
            }

            // 11.5 Validate Duplicate Entries
            if ($shiftPlan && $dumper && $loadingPoint && $dumpingPoint && $startTimeCarbon) {
                $resolvedStart = DispatchTripService::resolveDateTimeFromTime($startTimeCarbon->format('H:i:s'), $shiftPlan);
                $startTimeStrFormatted = $startTimeCarbon->format('H:i:s');
                $uniqueKey = sprintf(
                    '%d_%d_%d_%d_%s',
                    $shiftPlan->id,
                    $dumper->id,
                    $loadingPoint->id,
                    $dumpingPoint->id,
                    $resolvedStart
                );

                if (in_array($uniqueKey, $this->processedKeys)) {
                    $rowErrors[] = "Duplicate row found in the uploaded file for Dumper '{$dumperName}', Shift '{$shiftName}' at start time {$startTimeStrFormatted}.";
                } else {
                    $this->processedKeys[] = $uniqueKey;

                    $existingTrip = DispatchTrip::where('shift_plan_id', $shiftPlan->id)
                        ->where('dumper_equipment_id', $dumper->id)
                        ->where('loading_point_id', $loadingPoint->id)
                        ->where('dumping_point_id', $dumpingPoint->id)
                        ->where('start_time', $resolvedStart)
                        ->first();

                    if ($existingTrip) {
                        $rowErrors[] = "Dispatch trip '{$existingTrip->trip_reference_no}' already exists in database for Dumper '{$dumperName}' on Shift '{$shiftName}' at start time {$startTimeStrFormatted}.";
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
                        'shift_id' => $shift->id,
                        'site_id' => $site->id,
                        'dumper_equipment_id' => $dumper->id,
                        'driver_id' => $driver->id,
                        'excavator_equipment_id' => $excavator ? $excavator->id : null,
                        'loading_point_id' => $loadingPoint->id,
                        'dumping_point_id' => $dumpingPoint->id,
                        'start_time' => $startTimeCarbon->format('H:i:s'),
                        'end_time' => $endTimeCarbon->format('H:i:s'),
                        'quantity_bcm' => $quantityBcm,
                        'distance_meters' => $distanceMeters,
                        'total_cycles' => $totalCycles,
                    ];

                    $dispatchService->logTrip($data, auth()->id() ?? 1);
                    $this->successCount++;
                } catch (\Throwable $th) {
                    $this->errors[] = "Row {$rowNum}: Failed to save record - " . $th->getMessage();
                }
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
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value));
        }
        
        $value = trim($value);
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
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
