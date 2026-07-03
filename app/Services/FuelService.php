<?php

namespace App\Services;

use App\Models\FuelEntry;
use App\Models\FuelEntryAuditLog;
use App\Models\ShiftPlan;
use App\Models\ShiftEquipmentAllocation;
use App\Models\DispatchTrip;
use App\Exceptions\MachineNotInShiftException;
use App\Exceptions\ReadingRegressionException;
use App\Exceptions\ReadOnlyFieldMutationException;
use App\Exceptions\FuelRecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FuelService
{
    /**
     * Generate unique Fuel Reference Number.
     * Format: FUEL-{year}-{6-digit sequence}
     *
     * @return string
     */
    public function generateFuelRefNo(): string
    {
        return DB::transaction(function () {
            $year = date('Y');
            
            $lastEntry = FuelEntry::where('fuel_ref_no', 'like', "FUEL-{$year}-%")
                ->lockForUpdate()
                ->orderBy('fuel_ref_no', 'desc')
                ->first();

            $sequence = 1;
            if ($lastEntry) {
                $parts = explode('-', $lastEntry->fuel_ref_no);
                if (count($parts) === 3) {
                    $sequence = (int) $parts[2] + 1;
                }
            }

            return 'FUEL-' . $year . '-' . str_pad($sequence, 6, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Create a new Fuel Entry.
     *
     * @param array $data
     * @param int $userId
     * @return FuelEntry
     */
    public function createEntry(array $data, int $userId): FuelEntry
    {
        return DB::transaction(function () use ($data, $userId) {
            // 1. Validate shift_plan_id has status in ['published', 'in_progress']
            $shiftPlan = ShiftPlan::find($data['shift_plan_id']);
            if (!$shiftPlan || !in_array($shiftPlan->status, ['published', 'in_progress'])) {
                throw new MachineNotInShiftException("Machine Is Not Assigned To Current Shift");
            }

            // 2. Validate or resolve equipment_allocation_id belonging to shift_plan_id
            $allocation = null;
            if (!empty($data['equipment_allocation_id'])) {
                $allocation = ShiftEquipmentAllocation::where('id', $data['equipment_allocation_id'])
                    ->where('shift_plan_id', $data['shift_plan_id'])
                    ->first();
            } elseif (!empty($data['equipment_name_id'])) {
                $allocation = ShiftEquipmentAllocation::where('shift_plan_id', $data['shift_plan_id'])
                    ->where('equipment_name_id', $data['equipment_name_id'])
                    ->first();
            }

            if (!$allocation) {
                throw new MachineNotInShiftException("Machine Is Not Assigned To Current Shift");
            }

            // Ensure the resolved allocation ID is populated in the data array
            $data['equipment_allocation_id'] = $allocation->id;

            // 3. Fetch previous fuel entry for same machine to validate readings
            if (isset($data['hours_meter_reading'])) {
                $previousHour = FuelEntry::forMachine($data['equipment_allocation_id'])
                    ->where('status', 'active')
                    ->whereNotNull('hours_meter_reading')
                    ->orderBy('hours_meter_reading', 'desc')
                    ->first();

                if ($previousHour && $data['hours_meter_reading'] < $previousHour->hours_meter_reading) {
                    throw new ReadingRegressionException("Hour Meter Reading Cannot Be Less Than Previous Reading");
                }
            }

            if (isset($data['kilometer_reading'])) {
                $previousKm = FuelEntry::forMachine($data['equipment_allocation_id'])
                    ->where('status', 'active')
                    ->whereNotNull('kilometer_reading')
                    ->orderBy('kilometer_reading', 'desc')
                    ->first();

                if ($previousKm && $data['kilometer_reading'] < $previousKm->kilometer_reading) {
                    throw new ReadingRegressionException("Kilometer Reading Cannot Be Less Than Previous Reading");
                }
            }

            // 4. Compute fuel_consumption
            $openingFuel = isset($data['opening_fuel']) ? $data['opening_fuel'] : null;
            $fuelIssued = isset($data['fuel_issued']) ? $data['fuel_issued'] : null;
            $closingFuel = isset($data['closing_fuel']) ? $data['closing_fuel'] : null;

            if ($openingFuel !== null && $closingFuel !== null) {
                $fuelConsumption = $openingFuel + $fuelIssued - $closingFuel;
            } else {
                $fuelConsumption = $fuelIssued;
            }

            // 5. Fetch work_done_bcm stub
            $workDoneBcm = $this->resolveWorkDoneBcm($data['shift_plan_id'], $data['equipment_allocation_id']);
            $fuelPerBcm = null;
            if ($workDoneBcm !== null && $workDoneBcm > 0) {
                $fuelPerBcm = $fuelConsumption / $workDoneBcm;
            }

            // 6. Compute fuel_per_hour
            $fuelPerHour = null;
            if (isset($data['hours_meter_reading'])) {
                $previousHour = FuelEntry::forMachine($data['equipment_allocation_id'])
                    ->where('status', 'active')
                    ->whereNotNull('hours_meter_reading')
                    ->orderBy('hours_meter_reading', 'desc')
                    ->first();
                if ($previousHour) {
                    $diff = $data['hours_meter_reading'] - $previousHour->hours_meter_reading;
                    if ($diff > 0) {
                        $fuelPerHour = $fuelConsumption / $diff;
                    }
                }
            }

            // 7. Compute fuel_per_km
            $fuelPerKm = null;
            if (isset($data['kilometer_reading'])) {
                $previousKm = FuelEntry::forMachine($data['equipment_allocation_id'])
                    ->where('status', 'active')
                    ->whereNotNull('kilometer_reading')
                    ->orderBy('kilometer_reading', 'desc')
                    ->first();
                if ($previousKm) {
                    $diff = $data['kilometer_reading'] - $previousKm->kilometer_reading;
                    if ($diff > 0) {
                        $fuelPerKm = $fuelConsumption / $diff;
                    }
                }
            }

            // 8. Generate fuel_ref_no, set created_by, status
            $fuelRefNo = $this->generateFuelRefNo();

            $fuelLogDate = isset($data['fuel_log_date']) 
                ? Carbon::parse($data['fuel_log_date'])->format('Y-m-d H:i:s') 
                : ($shiftPlan->planning_date ? Carbon::parse($shiftPlan->planning_date)->format('Y-m-d H:i:s') : null);
            $shiftId = $data['shift_id'] ?? $shiftPlan->shift_id;
            $equipmentNameId = $data['equipment_name_id'] ?? $allocation->equipment_name_id;
            $equipmentId = $data['equipment_id'] ?? optional($allocation->equipmentName)->equipment_id;

            $entryData = array_merge($data, [
                'fuel_log_date' => $fuelLogDate,
                'shift_id' => $shiftId,
                'equipment_id' => $equipmentId,
                'equipment_name_id' => $equipmentNameId,
                'fuel_ref_no' => $fuelRefNo,
                'fuel_consumption' => $fuelConsumption,
                'work_done_bcm' => $workDoneBcm,
                'fuel_per_bcm' => $fuelPerBcm,
                'fuel_per_hour' => $fuelPerHour,
                'fuel_per_km' => $fuelPerKm,
                'created_by' => $userId,
                'status' => 'active',
            ]);

            return FuelEntry::create($entryData);
        });
    }

    /**
     * Update an existing Fuel Entry.
     *
     * @param int $id
     * @param array $data
     * @param int $userId
     * @return FuelEntry
     */
    public function updateEntry(int $id, array $data, int $userId): FuelEntry
    {
        return DB::transaction(function () use ($id, $data, $userId) {
            $entry = FuelEntry::find($id);
            if (!$entry) {
                throw new FuelRecordNotFoundException("Fuel Record Not Found");
            }

            // Reject editing voided records
            if ($entry->status === 'voided') {
                throw new ReadOnlyFieldMutationException("Voided records cannot be edited.");
            }

            // Reject payload that changes read-only fields
            $readOnlyFields = ['fuel_ref_no', 'created_by', 'created_at'];
            foreach ($readOnlyFields as $field) {
                if (array_key_exists($field, $data)) {
                    $originalVal = $entry->$field;
                    $newVal = $data[$field];
                    if ($newVal === null || $newVal === '') {
                        continue;
                    }
                    if ($field === 'created_at') {
                        if ($originalVal === null || $newVal === null) {
                            if ($originalVal !== $newVal) {
                                throw new ReadOnlyFieldMutationException("Field {$field} is read-only");
                            }
                        } else {
                            if (Carbon::parse($originalVal)->ne(Carbon::parse($newVal))) {
                                throw new ReadOnlyFieldMutationException("Field {$field} is read-only");
                            }
                        }
                    } else {
                        if (is_numeric($originalVal) && is_numeric($newVal)) {
                            if (abs((float)$originalVal - (float)$newVal) > 0.00001) {
                                throw new ReadOnlyFieldMutationException("Field {$field} is read-only");
                            }
                        } else {
                            if ($originalVal != $newVal) {
                                throw new ReadOnlyFieldMutationException("Field {$field} is read-only");
                            }
                        }
                    }
                }
            }

            // 1. Resolve the correct ShiftPlan
            $shiftPlan = null;
            if (isset($data['shift_plan_id'])) {
                $shiftPlan = ShiftPlan::find($data['shift_plan_id']);
            } else {
                $date = isset($data['fuel_log_date']) 
                    ? Carbon::parse($data['fuel_log_date'])->format('Y-m-d') 
                    : ($entry->fuel_log_date ? $entry->fuel_log_date->format('Y-m-d') : null);
                $shiftId = isset($data['shift_id']) ? $data['shift_id'] : $entry->shift_id;

                if ($date && $shiftId) {
                    $shiftPlan = ShiftPlan::whereDate('planning_date', $date)
                        ->where('shift_id', $shiftId)
                        ->first();
                }
            }

            // Fallback to current entry's shift plan if none resolved
            if (!$shiftPlan) {
                $shiftPlan = ShiftPlan::find($entry->shift_plan_id);
            }

            // Ensure shift plan is valid and active/published
            if (!$shiftPlan || !in_array($shiftPlan->status, ['published', 'in_progress', 'active', 'planned'])) {
                throw new MachineNotInShiftException("Machine Is Not Assigned To Current Shift");
            }

            // 2. Resolve the correct machine allocation
            $equipmentNameId = isset($data['equipment_name_id']) ? $data['equipment_name_id'] : $entry->equipment_name_id;

            $allocation = null;
            if (!empty($data['equipment_allocation_id'])) {
                $allocation = ShiftEquipmentAllocation::where('id', $data['equipment_allocation_id'])
                    ->where('shift_plan_id', $shiftPlan->id)
                    ->first();
            } else {
                $allocation = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlan->id)
                    ->where('equipment_name_id', $equipmentNameId)
                    ->first();
            }

            if (!$allocation) {
                throw new MachineNotInShiftException("Machine Is Not Assigned To Current Shift");
            }

            // Populate resolved related fields
            $data['shift_plan_id'] = $shiftPlan->id;
            $data['equipment_allocation_id'] = $allocation->id;
            $data['equipment_id'] = $allocation->equipmentName->equipment_id;
            $data['shift_id'] = $shiftPlan->shift_id;
            $data['fuel_log_date'] = isset($data['fuel_log_date']) 
                ? Carbon::parse($data['fuel_log_date'])->format('Y-m-d H:i:s') 
                : ($entry->fuel_log_date 
                    ? Carbon::parse($entry->fuel_log_date)->format('Y-m-d H:i:s') 
                    : ($shiftPlan->planning_date ? Carbon::parse($shiftPlan->planning_date)->format('Y-m-d H:i:s') : null)
                  );
            $data['equipment_name_id'] = $equipmentNameId;

            // Fill attributes with the updated data
            $entry->fill($data);

            // Readings validation against PREVIOUS entries
            if (isset($data['hours_meter_reading'])) {
                $previousHour = FuelEntry::forMachine($entry->equipment_allocation_id)
                    ->where('status', 'active')
                    ->where('id', '!=', $entry->id)
                    ->whereNotNull('hours_meter_reading')
                    ->orderBy('hours_meter_reading', 'desc')
                    ->first();

                if ($previousHour && $data['hours_meter_reading'] < $previousHour->hours_meter_reading) {
                    throw new ReadingRegressionException("Hour Meter Reading Cannot Be Less Than Previous Reading");
                }
            }

            if (isset($data['kilometer_reading'])) {
                $previousKm = FuelEntry::forMachine($entry->equipment_allocation_id)
                    ->where('status', 'active')
                    ->where('id', '!=', $entry->id)
                    ->whereNotNull('kilometer_reading')
                    ->orderBy('kilometer_reading', 'desc')
                    ->first();

                if ($previousKm && $data['kilometer_reading'] < $previousKm->kilometer_reading) {
                    throw new ReadingRegressionException("Kilometer Reading Cannot Be Less Than Previous Reading");
                }
            }

            // Fetch previous readings for calculations (diff)
            $previousHour = FuelEntry::forMachine($entry->equipment_allocation_id)
                ->where('status', 'active')
                ->where('id', '!=', $entry->id)
                ->whereNotNull('hours_meter_reading')
                ->orderBy('hours_meter_reading', 'desc')
                ->first();

            $previousKm = FuelEntry::forMachine($entry->equipment_allocation_id)
                ->where('status', 'active')
                ->where('id', '!=', $entry->id)
                ->whereNotNull('kilometer_reading')
                ->orderBy('kilometer_reading', 'desc')
                ->first();

            // Resolve work_done_bcm if not explicitly provided in payload (in case shift/machine changed)
            if (!array_key_exists('work_done_bcm', $data)) {
                $entry->work_done_bcm = $this->resolveWorkDoneBcm($entry->shift_plan_id, $entry->equipment_allocation_id);
            }

            // Recalculations
            if ($entry->opening_fuel !== null && $entry->closing_fuel !== null) {
                $entry->fuel_consumption = $entry->opening_fuel + $entry->fuel_issued - $entry->closing_fuel;
            } else {
                $entry->fuel_consumption = $entry->fuel_issued;
            }

            if ($entry->work_done_bcm !== null && $entry->work_done_bcm > 0) {
                $entry->fuel_per_bcm = $entry->fuel_consumption / $entry->work_done_bcm;
            } else {
                $entry->fuel_per_bcm = null;
            }

            if ($entry->hours_meter_reading !== null && $previousHour) {
                $diff = $entry->hours_meter_reading - $previousHour->hours_meter_reading;
                if ($diff > 0) {
                    $entry->fuel_per_hour = $entry->fuel_consumption / $diff;
                } else {
                    $entry->fuel_per_hour = null;
                }
            } else {
                $entry->fuel_per_hour = null;
            }

            if ($entry->kilometer_reading !== null && $previousKm) {
                $diff = $entry->kilometer_reading - $previousKm->kilometer_reading;
                if ($diff > 0) {
                    $entry->fuel_per_km = $entry->fuel_consumption / $diff;
                } else {
                    $entry->fuel_per_km = null;
                }
            } else {
                $entry->fuel_per_km = null;
            }

            $dirty = $entry->getDirty();
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $field => $newValue) {
                $oldValues[$field] = $entry->getOriginal($field);
                $newValues[$field] = $newValue;
            }

            $entry->updated_by = $userId;
            $entry->save();

            if (!empty($oldValues)) {
                FuelEntryAuditLog::create([
                    'fuel_entry_id' => $entry->id,
                    'changed_by' => $userId,
                    'old_values' => $oldValues,
                    'new_values' => $newValues,
                ]);
            }

            return $entry;
        });
    }

    /**
     * Get details of a single Fuel Entry.
     *
     * @param int $id
     * @return FuelEntry
     */
    public function getEntry(int $id): FuelEntry
    {
        $entry = FuelEntry::with([
            'shift',
            'shiftPlan.shift',
            'shiftPlan.site',
            'equipmentAllocation.equipmentName.equipment',
            'equipmentAllocation.parentCategory',
            'operator.employee',
            'createdBy.employee',
            'updatedBy.employee',
            'auditLogs.changedBy.employee'
        ])->find($id);

        if (!$entry) {
            throw new FuelRecordNotFoundException("Fuel Record Not Found");
        }

        // Compute dynamic attributes
        $entry->editable = $entry->status !== 'voided';
        $entry->last_updated_by = $entry->updatedBy ? (optional($entry->updatedBy->employee)->name ?? $entry->updatedBy->name) : null;
        $entry->last_updated_date = $entry->updated_at && $entry->updated_by ? $entry->updated_at->toDateTimeString() : null;

        return $entry;
    }

    /**
     * List fuel register with filters and pagination.
     *
     * @param array $filters
     * @return array
     */
    public function listRegister(array $filters): array
    {
        list($dateFrom, $dateTo) = $this->resolveDateRange($filters);

        $query = FuelEntry::with([
            'equipment',
            'equipmentName',
            'shift',
        ]);

        if ($dateFrom) {
            $query->where(function ($q) use ($dateFrom) {
                $q->where('fuel_entries.fuel_log_date', '>=', $dateFrom->format('Y-m-d'))
                  ->orWhere(function ($sq) use ($dateFrom) {
                      $sq->whereNull('fuel_entries.fuel_log_date')
                         ->whereHas('shiftPlan', function ($sp) use ($dateFrom) {
                             $sp->where('planning_date', '>=', $dateFrom->format('Y-m-d'));
                         });
                  });
            });
        }
        if ($dateTo) {
            $query->where(function ($q) use ($dateTo) {
                $q->where('fuel_entries.fuel_log_date', '<=', $dateTo->format('Y-m-d'))
                  ->orWhere(function ($sq) use ($dateTo) {
                      $sq->whereNull('fuel_entries.fuel_log_date')
                         ->whereHas('shiftPlan', function ($sp) use ($dateTo) {
                             $sp->where('planning_date', '<=', $dateTo->format('Y-m-d'));
                         });
                  });
            });
        }

        if (isset($filters['shift_id'])) {
            $query->whereHas('shiftPlan', function ($q) use ($filters) {
                $q->where('shift_id', $filters['shift_id']);
            });
        }

        if (isset($filters['equipment_id'])) {
            $query->where('fuel_entries.equipment_id', $filters['equipment_id']);
        }

        if (isset($filters['machine_type_id'])) {
            $query->where('fuel_entries.equipment_id', $filters['machine_type_id']);
        }

        if (isset($filters['machine_number_id'])) {
            $query->where('fuel_entries.equipment_name_id', $filters['machine_number_id']);
        }

        if (isset($filters['operator_id'])) {
            $query->where('fuel_entries.operator_id', $filters['operator_id']);
        }

        if (isset($filters['site_id'])) {
            $query->whereHas('shiftPlan', function ($q) use ($filters) {
                $q->where('site_id', $filters['site_id']);
            });
        }

        if (isset($filters['fuel_source'])) {
            $query->where('fuel_entries.fuel_source', $filters['fuel_source']);
        }

        if (isset($filters['fuel_ref_no'])) {
            $query->where('fuel_entries.fuel_ref_no', 'like', '%' . $filters['fuel_ref_no'] . '%');
        }

        if (isset($filters['flag'])) {
            if ($filters['flag'] === 'high_consumption') {
                $query->where('fuel_entries.fuel_consumption', '>=', config('fuel.high_consumption_threshold', 500.00));
            } elseif ($filters['flag'] === 'low_efficiency') {
                $query->where('fuel_entries.fuel_per_bcm', '>=', config('fuel.low_efficiency_threshold', 2.50));
            }
        }

        // Summary Calculations (before pagination)
        $totalFuelIssued = (float) (clone $query)->sum('fuel_issued');
        $totalFuelConsumption = (float) (clone $query)->sum('fuel_consumption');
        $avgFuelPerBcm = (clone $query)->whereNotNull('fuel_entries.fuel_per_bcm')->avg('fuel_entries.fuel_per_bcm');

        // Distinct machines count using fresh clone
        $distinctMachines = (int) (clone $query)->distinct()->count('fuel_entries.equipment_name_id');

        $summary = [
            'total_fuel_issued' => round($totalFuelIssued, 2),
            'total_fuel_consumption' => round($totalFuelConsumption, 2),
            'average_fuel_per_bcm' => $avgFuelPerBcm !== null ? round((float) $avgFuelPerBcm, 4) : null,
            'distinct_machines' => $distinctMachines,
        ];

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $records = $query->latest()->paginate($perPage);

        $efficiencyTrends = [];
        $trendItems = collect($records->items())->reverse();
        foreach ($trendItems as $entry) {
            $efficiencyTrends[] = [
                'machine_name' => optional($entry->equipmentName)->equipment_name ?? 'Unknown',
                'active_selection' => $entry->fuel_per_bcm !== null ? round((float) $entry->fuel_per_bcm, 4) : 0.0,
                'fleet_average' => $avgFuelPerBcm !== null ? round((float) $avgFuelPerBcm, 4) : null,
            ];
        }

        return [
            'records' => $records,
            'summary' => $summary,
            'efficiency_trends' => $efficiencyTrends,
        ];
    }

    /**
     * Get dashboard summary and charts data.
     *
     * @param array $filters
     * @return array|null
     */
    public function getDashboard(array $filters): ?array
    {
        list($dateFrom, $dateTo) = $this->resolveDateRange($filters, 'today');

        $query = FuelEntry::query();

        if ($dateFrom) {
            $query->whereHas('shiftPlan', function ($q) use ($dateFrom) {
                $q->where('planning_date', '>=', $dateFrom->format('Y-m-d'));
            });
        }
        if ($dateTo) {
            $query->whereHas('shiftPlan', function ($q) use ($dateTo) {
                $q->where('planning_date', '<=', $dateTo->format('Y-m-d'));
            });
        }

        if (!$query->exists()) {
            return null;
        }

        $totalFuelIssued = (float) $query->sum('fuel_issued');
        $totalFuelConsumption = (float) $query->sum('fuel_consumption');
        $totalWorkDone = (float) $query->sum('work_done_bcm');

        // If work done is null everywhere in range, efficiency is null
        $hasWorkDone = (clone $query)->whereNotNull('work_done_bcm')->exists();
        $fuelEfficiency = null;
        if ($hasWorkDone && $totalWorkDone > 0) {
            $fuelEfficiency = $totalFuelConsumption / $totalWorkDone;
        }

        $activeMachinesCount = (int) (clone $query)->distinct()->count('fuel_entries.equipment_name_id');

        $kpiCards = [
            'total_fuel_issued' => round($totalFuelIssued, 2),
            'total_fuel_consumption' => round($totalFuelConsumption, 2),
            'fuel_efficiency_l_per_bcm' => $fuelEfficiency !== null ? round($fuelEfficiency, 4) : null,
            'active_machines_count' => $activeMachinesCount,
        ];

        // trend_chart (hourly granularity)
        $hourlyRaw = (clone $query)
            ->selectRaw('HOUR(fuel_entries.created_at) as hr, SUM(fuel_entries.fuel_issued) as issued, SUM(fuel_entries.fuel_consumption) as consumption')
            ->groupByRaw('HOUR(fuel_entries.created_at)')
            ->get();

        $trendChart = [];
        for ($i = 0; $i < 24; $i++) {
            $hourStr = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
            $matched = $hourlyRaw->firstWhere('hr', $i);
            $trendChart[] = [
                'hour' => $hourStr,
                'fuel_issued' => $matched ? round((float)$matched->issued, 2) : 0.0,
                'fuel_consumption' => $matched ? round((float)$matched->consumption, 2) : 0.0,
            ];
        }

        // category_breakdown
        $categoryBreakdown = (clone $query)
            ->join('shift_equipment_allocations', 'fuel_entries.equipment_allocation_id', '=', 'shift_equipment_allocations.id')
            ->join('equipment_names', 'shift_equipment_allocations.equipment_name_id', '=', 'equipment_names.id')
            ->join('equipments', 'equipment_names.equipment_id', '=', 'equipments.id')
            ->selectRaw('equipments.id, equipments.name as category_name, SUM(fuel_entries.fuel_consumption) as total_consumption')
            ->groupBy('equipments.id', 'equipments.name')
            ->get()
            ->map(function ($item) use ($totalFuelConsumption) {
                $item->total_consumption = round((float)$item->total_consumption, 2);
                $item->percentage = $totalFuelConsumption > 0 
                    ? round(($item->total_consumption / $totalFuelConsumption) * 100, 2) 
                    : 0.0;
                return $item;
            });

        // machine_comparison
        $machineComparison = (clone $query)
            ->join('shift_equipment_allocations', 'fuel_entries.equipment_allocation_id', '=', 'shift_equipment_allocations.id')
            ->join('equipment_names', 'shift_equipment_allocations.equipment_name_id', '=', 'equipment_names.id')
            ->selectRaw('equipment_names.id, equipment_names.equipment_name, SUM(fuel_entries.fuel_consumption) as total_consumption, AVG(fuel_entries.fuel_per_bcm) as avg_efficiency')
            ->groupBy('equipment_names.id', 'equipment_names.equipment_name')
            ->get()
            ->map(function ($item) {
                $item->total_consumption = round((float)$item->total_consumption, 2);
                $item->avg_efficiency = $item->avg_efficiency !== null ? round((float)$item->avg_efficiency, 4) : null;
                return $item;
            });

        // top_fuel_consuming_machines
        $topFuelConsumingMachines = (clone $query)
            ->join('shift_equipment_allocations', 'fuel_entries.equipment_allocation_id', '=', 'shift_equipment_allocations.id')
            ->join('equipment_names', 'shift_equipment_allocations.equipment_name_id', '=', 'equipment_names.id')
            ->selectRaw('equipment_names.id, equipment_names.equipment_name, SUM(fuel_entries.fuel_consumption) as total_consumption')
            ->groupBy('equipment_names.id', 'equipment_names.equipment_name')
            ->orderBy('total_consumption', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                $item->total_consumption = round((float)$item->total_consumption, 2);
                return $item;
            });

        // lowest_efficiency_machines
        $lowestEfficiencyMachines = (clone $query)
            ->join('shift_equipment_allocations', 'fuel_entries.equipment_allocation_id', '=', 'shift_equipment_allocations.id')
            ->join('equipment_names', 'shift_equipment_allocations.equipment_name_id', '=', 'equipment_names.id')
            ->selectRaw('equipment_names.id, equipment_names.equipment_name, AVG(fuel_entries.fuel_per_bcm) as avg_fuel_per_bcm')
            ->whereNotNull('fuel_entries.fuel_per_bcm')
            ->groupBy('equipment_names.id', 'equipment_names.equipment_name')
            ->orderBy('avg_fuel_per_bcm', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                $val = (float) $item->avg_fuel_per_bcm;
                $item->avg_fuel_per_bcm = round($val, 4);
                
                $warning = config('fuel.efficiency_thresholds.warning', 2.50);
                $critical = config('fuel.efficiency_thresholds.critical', 4.00);
                
                $status = 'normal';
                if ($val >= $critical) {
                    $status = 'critical';
                } elseif ($val >= $warning) {
                    $status = 'warning';
                }
                $item->efficiency_status = $status;
                return $item;
            });

        // recent_entries
        $recentEntries = (clone $query)
            ->with([
                'shift',
                'shiftPlan.shift',
                'equipmentAllocation.equipmentName',
                'operator.employee'
            ])
            ->latest()
            ->limit(5)
            ->get();

        return [
            'kpi_cards' => $kpiCards,
            'trend_chart' => $trendChart,
            'category_breakdown' => $categoryBreakdown,
            'machine_comparison' => $machineComparison,
            'top_fuel_consuming_machines' => $topFuelConsumingMachines,
            'lowest_efficiency_machines' => $lowestEfficiencyMachines,
            'recent_entries' => $recentEntries,
            'has_work_done' => $hasWorkDone,
        ];
    }

    /**
     * Get performance summary and analytical metrics.
     *
     * @param array $filters
     * @return array|null
     */
    public function getPerformance(array $filters): ?array
    {
        list($dateFrom, $dateTo) = $this->resolveDateRange($filters, 'today');

        $query = FuelEntry::query();

        if ($dateFrom) {
            $query->whereHas('shiftPlan', function ($q) use ($dateFrom) {
                $q->where('planning_date', '>=', $dateFrom->format('Y-m-d'));
            });
        }
        if ($dateTo) {
            $query->whereHas('shiftPlan', function ($q) use ($dateTo) {
                $q->where('planning_date', '<=', $dateTo->format('Y-m-d'));
            });
        }

        if (!$query->exists()) {
            return null;
        }

        $totalFuelIssued = (float) $query->sum('fuel_issued');
        $totalFuelConsumption = (float) $query->sum('fuel_consumption');
        $totalWorkDone = (float) $query->sum('work_done_bcm');

        // Best/Worst performers
        $performerRaw = (clone $query)
            ->join('shift_equipment_allocations', 'fuel_entries.equipment_allocation_id', '=', 'shift_equipment_allocations.id')
            ->join('equipment_names', 'shift_equipment_allocations.equipment_name_id', '=', 'equipment_names.id')
            ->selectRaw('equipment_names.id, equipment_names.equipment_name, AVG(fuel_entries.fuel_per_bcm) as avg_fuel_per_bcm')
            ->whereNotNull('fuel_entries.fuel_per_bcm')
            ->groupBy('equipment_names.id', 'equipment_names.equipment_name')
            ->get();

        $bestPerformer = null;
        $worstPerformer = null;

        if ($performerRaw->isNotEmpty()) {
            $best = $performerRaw->sortBy('avg_fuel_per_bcm')->first();
            $worst = $performerRaw->sortByDesc('avg_fuel_per_bcm')->first();

            $bestPerformer = [
                'machine_id' => $best->id,
                'equipment_name' => $best->equipment_name,
                'fuel_per_bcm' => round((float)$best->avg_fuel_per_bcm, 4),
            ];

            $worstPerformer = [
                'machine_id' => $worst->id,
                'equipment_name' => $worst->equipment_name,
                'fuel_per_bcm' => round((float)$worst->avg_fuel_per_bcm, 4),
            ];
        }

        $hasWorkDone = (clone $query)->whereNotNull('work_done_bcm')->exists();
        $fuelEfficiency = null;
        if ($hasWorkDone && $totalWorkDone > 0) {
            $fuelEfficiency = $totalFuelConsumption / $totalWorkDone;
        }

        $kpiCards = [
            'total_fuel_issued' => round($totalFuelIssued, 2),
            'total_fuel_consumption' => round($totalFuelConsumption, 2),
            'fuel_efficiency_l_per_bcm' => $fuelEfficiency !== null ? round($fuelEfficiency, 4) : null,
            'best_performer' => $bestPerformer,
            'worst_performer' => $worstPerformer,
        ];

        // trend_chart (daily granularity)
        $trendChart = (clone $query)
            ->join('shift_plans', 'fuel_entries.shift_plan_id', '=', 'shift_plans.id')
            ->selectRaw('shift_plans.planning_date as date, SUM(fuel_entries.fuel_consumption) as consumption')
            ->groupBy('shift_plans.planning_date')
            ->orderBy('shift_plans.planning_date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => Carbon::parse($item->date)->format('Y-m-d'),
                    'consumption' => round((float)$item->consumption, 2),
                ];
            });

        // efficiency_trend (daily granularity)
        $efficiencyTrend = (clone $query)
            ->join('shift_plans', 'fuel_entries.shift_plan_id', '=', 'shift_plans.id')
            ->selectRaw('shift_plans.planning_date as date, AVG(fuel_entries.fuel_per_bcm) as avg_efficiency')
            ->whereNotNull('fuel_entries.fuel_per_bcm')
            ->groupBy('shift_plans.planning_date')
            ->orderBy('shift_plans.planning_date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => Carbon::parse($item->date)->format('Y-m-d'),
                    'avg_efficiency' => round((float)$item->avg_efficiency, 4),
                ];
            });

        // category_breakdown
        $categoryBreakdown = (clone $query)
            ->join('shift_equipment_allocations', 'fuel_entries.equipment_allocation_id', '=', 'shift_equipment_allocations.id')
            ->join('equipment_names', 'shift_equipment_allocations.equipment_name_id', '=', 'equipment_names.id')
            ->join('equipments', 'equipment_names.equipment_id', '=', 'equipments.id')
            ->selectRaw('equipments.id, equipments.name as category_name, SUM(fuel_entries.fuel_consumption) as total_consumption')
            ->groupBy('equipments.id', 'equipments.name')
            ->get()
            ->map(function ($item) use ($totalFuelConsumption) {
                $item->total_consumption = round((float)$item->total_consumption, 2);
                $item->percentage = $totalFuelConsumption > 0 
                    ? round(($item->total_consumption / $totalFuelConsumption) * 100, 2) 
                    : 0.0;
                return $item;
            });

        // machine_fuel_performance
        $machineFuelPerformance = (clone $query)
            ->join('shift_equipment_allocations', 'fuel_entries.equipment_allocation_id', '=', 'shift_equipment_allocations.id')
            ->join('equipment_names', 'shift_equipment_allocations.equipment_name_id', '=', 'equipment_names.id')
            ->selectRaw('equipment_names.id, equipment_names.equipment_name, SUM(fuel_entries.fuel_consumption) as total_consumption, AVG(fuel_entries.fuel_per_bcm) as avg_fuel_per_bcm, SUM(fuel_entries.work_done_bcm) as total_work_done')
            ->groupBy('equipment_names.id', 'equipment_names.equipment_name')
            ->orderByRaw('AVG(fuel_entries.fuel_per_bcm) ASC')
            ->get()
            ->map(function ($item, $index) {
                return [
                    'machine_id' => $item->id,
                    'equipment_name' => $item->equipment_name,
                    'total_consumption' => round((float)$item->total_consumption, 2),
                    'total_work_done' => $item->total_work_done !== null ? round((float)$item->total_work_done, 2) : null,
                    'avg_fuel_per_bcm' => $item->avg_fuel_per_bcm !== null ? round((float)$item->avg_fuel_per_bcm, 4) : null,
                    'performance_rank' => $index + 1,
                ];
            });

        return [
            'kpi_cards' => $kpiCards,
            'trend_chart' => $trendChart,
            'efficiency_trend' => $efficiencyTrend,
            'category_breakdown' => $categoryBreakdown,
            'machine_fuel_performance' => $machineFuelPerformance,
            'has_work_done' => $hasWorkDone,
        ];
    }

    /**
     * Get allocation tracking details.
     *
     * @param array $filters
     * @return array
     */
    public function getAllocationTracking(array $filters): array
    {
        list($dateFrom, $dateTo) = $this->resolveDateRange($filters);

        $query = FuelEntry::join('shift_plans', 'fuel_entries.shift_plan_id', '=', 'shift_plans.id')
            ->join('shifts', 'shift_plans.shift_id', '=', 'shifts.id')
            ->join('shift_equipment_allocations', 'fuel_entries.equipment_allocation_id', '=', 'shift_equipment_allocations.id')
            ->join('equipment_names', 'shift_equipment_allocations.equipment_name_id', '=', 'equipment_names.id')
            ->selectRaw('
                shift_plans.planning_date as planning_date,
                shifts.id as shift_id,
                shifts.shift_name as shift_name,
                equipment_names.id as equipment_name_id,
                equipment_names.equipment_name as equipment_name,
                SUM(fuel_entries.fuel_issued) as fuel_allocated_liters,
                COUNT(fuel_entries.id) as entry_count
            ');

        if ($dateFrom) {
            $query->where('shift_plans.planning_date', '>=', $dateFrom->format('Y-m-d'));
        }
        if ($dateTo) {
            $query->where('shift_plans.planning_date', '<=', $dateTo->format('Y-m-d'));
        }

        if (isset($filters['shift_id'])) {
            $query->where('shift_plans.shift_id', $filters['shift_id']);
        }
        if (isset($filters['machine_number_id'])) {
            $query->where('shift_equipment_allocations.equipment_name_id', $filters['machine_number_id']);
        }
        if (isset($filters['site_id'])) {
            $query->where('shift_plans.site_id', $filters['site_id']);
        }

        $query->groupBy('shift_plans.planning_date', 'shifts.id', 'shifts.shift_name', 'equipment_names.id', 'equipment_names.equipment_name');

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $records = $query->paginate($perPage);

        return [
            'records' => $records,
        ];
    }

    /**
     * Get machine-wise fuel summary and rollup.
     *
     * @param array $filters
     * @return array
     */
    public function getSummary(array $filters): array
    {
        list($dateFrom, $dateTo) = $this->resolveDateRange($filters);

        $query = FuelEntry::query();

        if ($dateFrom) {
            $query->whereHas('shiftPlan', function ($q) use ($dateFrom) {
                $q->where('planning_date', '>=', $dateFrom->format('Y-m-d'));
            });
        }
        if ($dateTo) {
            $query->whereHas('shiftPlan', function ($q) use ($dateTo) {
                $q->where('planning_date', '<=', $dateTo->format('Y-m-d'));
            });
        }

        if (isset($filters['shift_id'])) {
            $query->whereHas('shiftPlan', function ($q) use ($filters) {
                $q->where('shift_id', $filters['shift_id']);
            });
        }
        if (isset($filters['machine_number_id'])) {
            $query->whereHas('equipmentAllocation', function ($q) use ($filters) {
                $q->where('equipment_name_id', $filters['machine_number_id']);
            });
        }
        if (isset($filters['site_id'])) {
            $query->whereHas('shiftPlan', function ($q) use ($filters) {
                $q->where('site_id', $filters['site_id']);
            });
        }

        $entries = $query->with('equipmentAllocation.equipmentName')->get();

        $grouped = $entries->groupBy(function ($entry) {
            return optional($entry->equipmentAllocation)->equipment_name_id;
        });

        $machines = [];
        $totalFuelIssued = 0.0;
        $totalClosingFuel = 0.0;
        $totalConsumption = 0.0;

        foreach ($grouped as $machineId => $machineEntries) {
            if (!$machineId) continue;

            $firstEntry = $machineEntries->first();
            $machineName = optional(optional($firstEntry->equipmentAllocation)->equipmentName)->equipment_name ?? 'Unknown Machine';

            $fuelIssuedLiters = $machineEntries->sum('fuel_issued');
            $totalConsumptionLiters = $machineEntries->sum('fuel_consumption');

            // Latest closing fuel in the period
            $latestWithClosing = $machineEntries->sortByDesc('created_at')->first(function ($e) {
                return $e->closing_fuel !== null;
            });

            $closingFuelLiters = $latestWithClosing ? (float) $latestWithClosing->closing_fuel : null;

            $machines[] = [
                'machine_id' => $machineId,
                'machine_name' => $machineName,
                'fuel_issued_liters' => round($fuelIssuedLiters, 2),
                'closing_fuel_liters' => $closingFuelLiters !== null ? round($closingFuelLiters, 2) : null,
                'total_consumption_liters' => round($totalConsumptionLiters, 2),
            ];

            $totalFuelIssued += $fuelIssuedLiters;
            $totalClosingFuel += ($closingFuelLiters ?? 0.0);
            $totalConsumption += $totalConsumptionLiters;
        }

        return [
            'machines' => $machines,
            'totals' => [
                'total_fuel_issued_liters' => round($totalFuelIssued, 2),
                'total_closing_fuel_liters' => round($totalClosingFuel, 2),
                'total_consumption_liters' => round($totalConsumption, 2),
            ]
        ];
    }

    /**
     * Resolve work done in BCM from the Dispatch/Production module.
     *
     * @param int $shiftPlanId
     * @param int $equipmentAllocationId
     * @return float|null
     */
    public function resolveWorkDoneBcm(int $shiftPlanId, int $equipmentAllocationId): ?float
    {
        $allocation = ShiftEquipmentAllocation::find($equipmentAllocationId);
        if (!$allocation) {
            return null;
        }

        $equipmentNameId = $allocation->equipment_name_id;

        $hasTrips = DispatchTrip::where('shift_plan_id', $shiftPlanId)
            ->where(function ($query) use ($equipmentNameId) {
                $query->where('excavator_equipment_id', $equipmentNameId)
                      ->orWhere('dumper_equipment_id', $equipmentNameId);
            })
            ->exists();

        if (!$hasTrips) {
            return null;
        }

        // Query DispatchTrip BCM for this equipment name id (either as excavator or dumper) in this shift plan
        $totalBcm = DispatchTrip::where('shift_plan_id', $shiftPlanId)
            ->where(function ($query) use ($equipmentNameId) {
                $query->where('excavator_equipment_id', $equipmentNameId)
                      ->orWhere('dumper_equipment_id', $equipmentNameId);
            })
            ->sum('quantity_bcm');

        return (float) $totalBcm;
    }

    /**
     * Recalculate work_done_bcm and fuel_per_bcm for all active fuel entries 
     * matching the given shift plan and equipment name.
     *
     * @param int $shiftPlanId
     * @param int $equipmentNameId
     * @return void
     */
    public function recalculateFuelEntryBcm(int $shiftPlanId, int $equipmentNameId)
    {
        $entries = FuelEntry::where('shift_plan_id', $shiftPlanId)
            ->where('equipment_name_id', $equipmentNameId)
            ->where('status', 'active')
            ->get();

        foreach ($entries as $entry) {
            $workDoneBcm = $this->resolveWorkDoneBcm($entry->shift_plan_id, $entry->equipment_allocation_id);
            $entry->work_done_bcm = $workDoneBcm;

            if ($workDoneBcm !== null && $workDoneBcm > 0) {
                $entry->fuel_per_bcm = $entry->fuel_consumption / $workDoneBcm;
            } else {
                $entry->fuel_per_bcm = null;
            }

            $entry->save();
        }
    }

    /**
     * Resolve date range helper.
     *
     * @param array $filters
     * @param string|null $defaultRange
     * @return array
     */
    protected function resolveDateRange(array $filters, ?string $defaultRange = null): array
    {
        $dateFrom = null;
        $dateTo = null;

        $range = $filters['range'] ?? ($filters['period'] ?? $defaultRange);

        if ($range === 'today') {
            $dateFrom = Carbon::today()->startOfDay();
            $dateTo = Carbon::today()->endOfDay();
        } elseif ($range === 'yesterday') {
            $dateFrom = Carbon::yesterday()->startOfDay();
            $dateTo = Carbon::yesterday()->endOfDay();
        } elseif ($range === 'weekly') {
            $dateFrom = Carbon::now()->startOfWeek()->startOfDay();
            $dateTo = Carbon::now()->endOfWeek()->endOfDay();
        } elseif ($range === 'monthly') {
            $dateFrom = Carbon::now()->startOfMonth()->startOfDay();
            $dateTo = Carbon::now()->endOfMonth()->endOfDay();
        } elseif ($range === 'yearly') {
            $dateFrom = Carbon::now()->startOfYear()->startOfDay();
            $dateTo = Carbon::now()->endOfYear()->endOfDay();
        } elseif ($range === 'current_shift') {
            $dateFrom = Carbon::today()->startOfDay();
            $dateTo = Carbon::today()->endOfDay();
        } elseif ($range === 'custom' || isset($filters['date_from']) || isset($filters['date_to'])) {
            if (isset($filters['date_from']) && !empty($filters['date_from'])) {
                $val = $filters['date_from'];
                $dateFrom = strpos($val, '/') !== false 
                    ? Carbon::createFromFormat('d/m/Y', $val)->startOfDay() 
                    : Carbon::parse($val)->startOfDay();
            }
            if (isset($filters['date_to']) && !empty($filters['date_to'])) {
                $val = $filters['date_to'];
                $dateTo = strpos($val, '/') !== false 
                    ? Carbon::createFromFormat('d/m/Y', $val)->endOfDay() 
                    : Carbon::parse($val)->endOfDay();
            }
        } else {
            if ($defaultRange === 'today') {
                $dateFrom = Carbon::today()->startOfDay();
                $dateTo = Carbon::today()->endOfDay();
            }
        }

        if ($dateFrom && $dateTo && $dateFrom->gt($dateTo)) {
            throw new ReadingRegressionException("Invalid Date Range Selected");
        }

        return [$dateFrom, $dateTo];
    }
}
