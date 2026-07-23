<?php

namespace App\Imports;

use App\Models\Delay;
use App\Models\DelayCategory;
use App\Models\Shift;
use App\Models\ShiftPlan;
use App\Models\EquipmentName;
use App\Models\ShiftEquipmentAllocation;
use App\Models\BreakdownTicket;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class DelayImport implements ToCollection, WithHeadingRow
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
            $shiftDateStr = isset($rowArray['shift_date']) ? trim((string)$rowArray['shift_date']) : '';
            $shiftName = isset($rowArray['shift_name']) ? trim((string)$rowArray['shift_name']) : '';
            $categoryStr = isset($rowArray['delay_category']) ? trim((string)$rowArray['delay_category']) : '';
            $subcategoryStr = isset($rowArray['delay_subcategory']) ? trim((string)$rowArray['delay_subcategory']) : '';
            $startTimeStr = isset($rowArray['start_time']) ? trim((string)$rowArray['start_time']) : '';
            $endTimeStr = isset($rowArray['end_time']) ? trim((string)$rowArray['end_time']) : '';
            $description = isset($rowArray['description']) ? trim((string)$rowArray['description']) : '';
            $remarks = isset($rowArray['remarks']) ? trim((string)$rowArray['remarks']) : '';
            $severity = isset($rowArray['severity']) ? strtoupper(trim((string)$rowArray['severity'])) : '';
            $eqNameStr = isset($rowArray['equipment_name']) ? trim((string)$rowArray['equipment_name']) : '';
            $linkedBreakdownTicketStr = isset($rowArray['linked_breakdown_ticket']) ? trim((string)$rowArray['linked_breakdown_ticket']) : '';

            // 1. Validate required fields
            $rowErrors = [];

            // 1. Validate required fields
            if ($shiftDateStr === '') {
                $rowErrors[] = "Shift Date is required.";
            }
            if ($shiftName === '') {
                $rowErrors[] = "Shift Name is required.";
            }
            if ($categoryStr === '') {
                $rowErrors[] = "Delay Category is required.";
            }
            if ($startTimeStr === '') {
                $rowErrors[] = "Start Time is required.";
            }
            if ($description === '') {
                $rowErrors[] = "Description is required.";
            }

            // Validate that times are not 00:00:00 or 00:00
            if ($startTimeStr !== '' && $this->isZeroTime($startTimeStr)) {
                $rowErrors[] = "Start Time cannot be 00:00:00 or 00:00.";
            }
            if ($endTimeStr !== '' && $this->isZeroTime($endTimeStr)) {
                $rowErrors[] = "End Time cannot be 00:00:00 or 00:00.";
            }

            // 2. Parse Date
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

            // 3. Resolve Shift
            $shift = null;
            if ($shiftName !== '') {
                $shift = Shift::where('shift_name', $shiftName)->first();
                if (!$shift) {
                    $rowErrors[] = "Shift with name '{$shiftName}' not found.";
                }
            }

            // 4. Resolve Shift Plan
            $shiftPlan = null;
            if ($shiftDate && $shift) {
                $shiftPlan = ShiftPlan::where('shift_id', $shift->id)
                    ->where('planning_date', $shiftDate->toDateString())
                    ->first();
                if (!$shiftPlan) {
                    $rowErrors[] = "Shift plan not found for date {$shiftDate->toDateString()} and shift {$shiftName}.";
                }
            }

            // 5. Resolve Delay Category
            $delayCategory = null;
            if ($categoryStr !== '') {
                $delayCategory = DelayCategory::where('delay_category', $categoryStr)->first();
                if (!$delayCategory) {
                    $rowErrors[] = "Delay Category '{$categoryStr}' not found.";
                } elseif ((int)$delayCategory->is_active !== 1) {
                    $rowErrors[] = "Delay Category '{$categoryStr}' is inactive.";
                }
            }

            // 6. Check Machine Breakdown constraints
            $isMachineBreakdown = false;
            if ($delayCategory) {
                $isMachineBreakdown = (strtolower(str_replace([' ', '-'], '_', $delayCategory->delay_category)) === 'machine_breakdown');
                if ($isMachineBreakdown && $eqNameStr === '') {
                    $rowErrors[] = "Equipment Name is required for Machine Breakdown category.";
                }
            }

            // 7. Resolve Equipment & Validate Allocation
            $eqName = null;
            if ($eqNameStr !== '') {
                $eqName = EquipmentName::where('equipment_name', $eqNameStr)->first();
                if (!$eqName) {
                    $rowErrors[] = "Equipment Name '{$eqNameStr}' not found.";
                } elseif ($shiftPlan) {
                    // Check allocation in shift plan
                    $allocation = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlan->id)
                        ->where('equipment_name_id', $eqName->id)
                        ->first();
                    if (!$allocation) {
                        $rowErrors[] = "Machine '{$eqNameStr}' is not assigned in that shift plan.";
                    }
                }
            }

            // 8. Resolve and Validate Linked Breakdown Ticket
            $breakdown = null;
            if ($linkedBreakdownTicketStr !== '') {
                $breakdown = BreakdownTicket::where('ticket_number', $linkedBreakdownTicketStr)->first();
                if (!$breakdown) {
                    $rowErrors[] = "Linked breakdown ticket '{$linkedBreakdownTicketStr}' not found.";
                } elseif ($shiftPlan) {
                    // Verify association with shift plan
                    if ($breakdown->equipment_allocation_id) {
                        $alloc = ShiftEquipmentAllocation::find($breakdown->equipment_allocation_id);
                        if (!$alloc || $alloc->shift_plan_id != $shiftPlan->id) {
                            $rowErrors[] = "The selected breakdown ticket is not associated with this shift plan.";
                        }
                    } else {
                        if ($breakdown->shift_id != $shiftPlan->shift_id) {
                            $rowErrors[] = "The selected breakdown ticket is not associated with this shift plan.";
                        }
                    }
                }
            }

            // 9. Parse Times & Calculate Duration
            $start = null;
            $end = null;
            $durationMinutes = null;
            $estimatedProductionLoss = null;
            $averageProductionRate = (float) config('mining.average_production_rate_bcm_per_hour', 150.00);

            if ($startTimeStr !== '' && $shiftPlan) {
                try {
                    // Ensure times are properly parsed as full carbon times using the shift plan date context
                    $planningDateStr = Carbon::parse($shiftPlan->planning_date)->toDateString();
                    $startCarbon = $this->parseTime($startTimeStr);
                    $start = Carbon::parse($planningDateStr . ' ' . $startCarbon->format('H:i:s'));
                    
                    if ($endTimeStr !== '') {
                        $endCarbon = $this->parseTime($endTimeStr);
                        $end = Carbon::parse($planningDateStr . ' ' . $endCarbon->format('H:i:s'));
                        if ($end->lt($start)) {
                            $end->addDay(); // handles overnight delays
                        }
                        $durationMinutes = $start->diffInMinutes($end);
                        $durationHours = $durationMinutes / 60.0;
                        $estimatedProductionLoss = $durationHours * $averageProductionRate;
                    }
                } catch (\Throwable $e) {
                    $rowErrors[] = "Invalid time format.";
                }
            }

            // 9.5 Validate Duplicate Entries
            if ($shiftPlan && $delayCategory && $start) {
                $startTimeStrFormatted = $start->format('H:i:s');
                $eqIdStr = $eqName ? (string) $eqName->id : 'none';
                
                $uniqueKey = sprintf(
                    '%d_%d_%s_%s',
                    $shiftPlan->id,
                    $delayCategory->id,
                    $startTimeStrFormatted,
                    $eqIdStr
                );

                if (in_array($uniqueKey, $this->processedKeys)) {
                    $rowErrors[] = "Duplicate row found in the uploaded file for Category '{$categoryStr}', Shift '{$shiftName}' at start time {$startTimeStrFormatted}.";
                } else {
                    $this->processedKeys[] = $uniqueKey;

                    $query = Delay::where('shift_plan_id', $shiftPlan->id)
                        ->where('delay_category_id', $delayCategory->id)
                        ->where('start_time', $startTimeStrFormatted);

                    if ($eqName) {
                        $query->where('equipment_name_id', $eqName->id);
                    }

                    $existingDelay = $query->first();

                    if ($existingDelay) {
                        $rowErrors[] = "Delay entry '{$existingDelay->delay_ref_no}' already exists in database for Category '{$categoryStr}' on Shift '{$shiftName}' at start time {$startTimeStrFormatted}.";
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

            // 10. Database transaction to generate delay ref number and save
            try {
                DB::transaction(function () use ($shiftPlan, $shift, $delayCategory, $subcategoryStr, $start, $end, $durationMinutes, $estimatedProductionLoss, $averageProductionRate, $severity, $breakdown, $eqName, $description, $remarks, $year) {
                    
                    // Generate unique ref no inside transaction lock
                    $lastEntry = Delay::where('delay_ref_no', 'like', "DLY-{$year}-%")
                        ->lockForUpdate()
                        ->orderBy('delay_ref_no', 'desc')
                        ->first();

                    $sequence = 1;
                    if ($lastEntry) {
                        $parts = explode('-', $lastEntry->delay_ref_no);
                        if (count($parts) === 3) {
                            $sequence = (int) $parts[2] + 1;
                        }
                    }
                    $delayRefNo = 'DLY-' . $year . '-' . str_pad($sequence, 6, '0', STR_PAD_LEFT);

                    $delayData = [
                        'delay_ref_no' => $delayRefNo,
                        'shift_plan_id' => $shiftPlan->id,
                        'shift_id' => $shift->id,
                        'shift_date' => Carbon::parse($shiftPlan->planning_date)->toDateString(),
                        'shift_name' => $shiftPlan->shift->shift_name,
                        'delay_log_date' => Carbon::parse($shiftPlan->planning_date)->toDateString() . ' ' . $start->format('H:i:s'),
                        'delay_category_id' => $delayCategory->id,
                        'delay_subcategory' => $subcategoryStr !== '' ? $subcategoryStr : null,
                        'start_time' => $start->format('H:i:s'),
                        'end_time' => $end ? $end->format('H:i:s') : null,
                        'duration_minutes' => $durationMinutes,
                        'severity' => in_array($severity, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']) ? $severity : $this->calculateSeverity($durationMinutes),
                        'linked_breakdown_id' => $breakdown ? $breakdown->id : null,
                        'equipment_id' => $eqName ? $eqName->equipment_id : null,
                        'equipment_name_id' => $eqName ? $eqName->id : null,
                        'average_production_rate_per_hour' => $averageProductionRate,
                        'estimated_production_loss_bcm' => $estimatedProductionLoss,
                        'description' => $description,
                        'remarks' => $remarks !== '' ? $remarks : null,
                        'created_by' => auth()->id() ?? $shiftPlan->created_by ?? $shiftPlan->supervisor_id ?? 1,
                    ];

                    Delay::create($delayData);
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

    private function calculateSeverity(?int $durationMinutes): string
    {
        if (is_null($durationMinutes) || $durationMinutes <= 30) {
            return 'LOW';
        }
        if ($durationMinutes <= 120) {
            return 'MEDIUM';
        }
        if ($durationMinutes <= 240) {
            return 'HIGH';
        }
        return 'CRITICAL';
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
