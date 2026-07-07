<?php

namespace App\Services;

use App\Models\ShiftPlan;
use App\Models\DispatchTrip;
use App\Models\ShiftEquipmentAllocation;
use App\Models\ShiftWorkforceDeployment;
use App\Models\FuelEntry;
use App\Models\Delay;
use App\Models\DelayCategory;
use App\Models\BreakdownTicket;

class ShiftPlanSummaryService
{
    /**
     * Retrieve the consolidated operational summary for a CLOSED shift plan.
     *
     * @param int $shiftId
     * @return array
     */
    public function getClosedShiftPlanSummary(int $shiftId): array
    {
        $shiftPlan = ShiftPlan::with([
            'shift',
            'site',
            'supervisor.employee',
            'siteIncharge.employee'
        ])->find($shiftId);

        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan Not Found.',
            ];
        }

        // Check if the shift plan is closed (status must be 'closed' or 'completed')
        if (!in_array(strtolower($shiftPlan->status), ['closed', 'completed'])) {
            return [
                'status' => 422,
                'message' => 'Summary Is Only Available For Closed Shift Plans.',
            ];
        }

        $data = [
            'shift_plan' => $this->buildShiftPlan($shiftPlan),
            'production_summary' => $this->buildProductionSummary($shiftPlan),
            'equipment_summary' => $this->buildEquipmentSummary($shiftPlan),
            'workforce_summary' => $this->buildWorkforceSummary($shiftPlan),
            'fuel_summary' => $this->buildFuelSummary($shiftPlan),
            'delay_summary' => $this->buildDelaySummary($shiftPlan),
            'breakdown_summary' => $this->buildBreakdownSummary($shiftPlan),
            'safety_summary' => $this->buildSafetySummary($shiftPlan),
            'fleet_performance' => $this->buildFleetPerformance($shiftPlan),
        ];

        return [
            'status' => 200,
            'message' => 'Shift plan summary retrieved successfully.',
            'data' => $data,
        ];
    }

    /**
     * Build the base shift plan summary info.
     */
    private function buildShiftPlan(ShiftPlan $shift): array
    {
        $supervisorName = null;
        if ($shift->supervisor) {
            $emp = $shift->supervisor->employee;
            $supervisorName = $emp ? $emp->name : $shift->supervisor->email;
        }

        $siteInchargeName = null;
        if ($shift->siteIncharge) {
            $emp = $shift->siteIncharge->employee;
            $siteInchargeName = $emp ? $emp->name : $shift->siteIncharge->email;
        }

        return [
            'id' => $shift->id,
            'shift_reference_number' => $shift->reference_no,
            'planning_date' => $shift->planning_date ? $shift->planning_date->format('Y-m-d') : null,
            'shift_id' => $shift->shift_id,
            'location_id' => $shift->site_id,
            'location_name' => optional($shift->site)->site_name,
            'target_bcm' => !is_null($shift->target_bcm) ? round((float) $shift->target_bcm, 2) : 0.00,
            'supervisor_name' => $supervisorName,
            'site_incharge_name' => $siteInchargeName,
            'status' => $shift->status,
            'shift' => $shift->shift ? [
                'id' => $shift->shift->id,
                'shift_name' => $shift->shift->shift_name,
                'start_time' => $shift->shift->start_time,
                'end_time' => $shift->shift->end_time,
                'minimum_working_hours' => $shift->shift->minimum_working_hours,
                'is_night_shift' => $shift->shift->is_night_shift,
            ] : null,
        ];
    }

    /**
     * Build the production summary.
     */
    private function buildProductionSummary(ShiftPlan $shift): array
    {
        $actualBcm = round((float) DispatchTrip::where('shift_plan_id', $shift->id)->sum('quantity_bcm'), 2);
        $targetBcm = !is_null($shift->target_bcm) ? round((float) $shift->target_bcm, 2) : 0.00;

        $achievementPercent = 0.00;
        if ($targetBcm > 0) {
            $achievementPercent = round(($actualBcm / $targetBcm) * 100, 2);
        }

        $varianceBcm = round($actualBcm - $targetBcm, 2);

        return [
            'actual_bcm' => $actualBcm,
            'achievement_percent' => $achievementPercent,
            'variance_bcm' => $varianceBcm,
        ];
    }

    /**
     * Build the equipment summary.
     */
    /**
     * Build the equipment summary.
     */
    private function buildEquipmentSummary(ShiftPlan $shift): array
    {
        $allocations = ShiftEquipmentAllocation::where('shift_plan_id', $shift->id)
            ->with('equipmentName.equipment')
            ->get();

        $totalAllocated = $allocations->count();

        // Unique utilized equipment names (dumpers + excavators) from dispatch_trips
        $utilizedDumperIds = DispatchTrip::where('shift_plan_id', $shift->id)
            ->whereNotNull('dumper_equipment_id')
            ->distinct()
            ->pluck('dumper_equipment_id')
            ->toArray();

        $utilizedExcavatorIds = DispatchTrip::where('shift_plan_id', $shift->id)
            ->whereNotNull('excavator_equipment_id')
            ->distinct()
            ->pluck('excavator_equipment_id')
            ->toArray();

        $utilizedEquipmentIds = array_unique(array_merge($utilizedDumperIds, $utilizedExcavatorIds));

        $equipmentUtilized = $allocations->whereIn('equipment_name_id', $utilizedEquipmentIds)->count();

        // Calculate equipment availability %
        $shiftDurationHours = optional($shift->shift)->minimum_working_hours ?: 8.00;
        $periodHours = $totalAllocated * $shiftDurationHours;

        $allocationIds = $allocations->pluck('id')->toArray();
        $totalDowntimeMinutes = 0;
        if (!empty($allocationIds)) {
            $totalDowntimeMinutes = (int) BreakdownTicket::whereIn('equipment_allocation_id', $allocationIds)
                ->sum('downtime_minutes');
        }
        $totalDowntimeHours = round($totalDowntimeMinutes / 60, 2);

        $equipmentAvailabilityPercent = 100.00;
        if ($periodHours > 0) {
            $equipmentAvailabilityPercent = round((($periodHours - $totalDowntimeHours) / $periodHours) * 100, 2);
            $equipmentAvailabilityPercent = max(0.00, min(100.00, $equipmentAvailabilityPercent));
        }

        // Calculate top performing equipment
        $dumperSummaryData = DispatchTrip::where('shift_plan_id', $shift->id)
            ->select('dumper_equipment_id', 'equipment_names.equipment_name as dumper_number')
            ->selectRaw('COUNT(dispatch_trips.id) as total_trips')
            ->selectRaw('SUM(quantity_bcm) as total_quantity_bcm')
            ->selectRaw('AVG(cycle_time_minutes) as average_cycle_time_minutes')
            ->join('equipment_names', 'equipment_names.id', '=', 'dispatch_trips.dumper_equipment_id')
            ->groupBy('dumper_equipment_id', 'equipment_names.equipment_name')
            ->get();

        $maxQuantity = 0.0001; // Avoid division by zero
        $maxTrips = 1;
        $minCycleTime = 999999;

        foreach ($dumperSummaryData as $row) {
            if ((float) $row->total_quantity_bcm > $maxQuantity) {
                $maxQuantity = (float) $row->total_quantity_bcm;
            }
            if ((int) $row->total_trips > $maxTrips) {
                $maxTrips = (int) $row->total_trips;
            }
            if ((float) $row->average_cycle_time_minutes < $minCycleTime && (float) $row->average_cycle_time_minutes > 0) {
                $minCycleTime = (float) $row->average_cycle_time_minutes;
            }
        }

        if ($minCycleTime === 999999) {
            $minCycleTime = 0;
        }

        $formattedDumpers = [];
        foreach ($dumperSummaryData as $row) {
            $qty = (float) $row->total_quantity_bcm;
            $trips = (int) $row->total_trips;
            $avgCycle = (float) $row->average_cycle_time_minutes;

            $quantityScore = ($qty / $maxQuantity) * 100;
            $tripScore = ($trips / $maxTrips) * 100;
            $cycleScore = $avgCycle > 0 ? ($minCycleTime / $avgCycle) * 100 : 0;

            // Note: Weightings are 50% Quantity, 25% Trip count, and 25% Cycle Time.
            $compositeScore = ($quantityScore * 0.5) + ($tripScore * 0.25) + ($cycleScore * 0.25);

            $formattedDumpers[] = [
                'equipment_id' => $row->dumper_equipment_id,
                'equipment_name' => $row->dumper_number,
                'score' => round($compositeScore, 2)
            ];
        }

        // Apply dense ranking based on score descending
        usort($formattedDumpers, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $topPerformingEquipment = array_slice($formattedDumpers, 0, 3);

        // Excavator Performance List
        $excavatorPerformance = [];
        $excavatorAllocations = $allocations->filter(function ($allocation) {
            $catName = $allocation->equipmentName && $allocation->equipmentName->equipment
                ? $allocation->equipmentName->equipment->name
                : '';
            return stripos($catName, 'excavator') !== false || (stripos($catName, 'dumper') === false && $allocation->parent_equipment_id === null);
        })->values();

        $totalExcavators = $excavatorAllocations->count();
        $targetBcmPerExcavator = $totalExcavators > 0 ? round($shift->target_bcm / $totalExcavators, 2) : 0.00;

        foreach ($excavatorAllocations as $allocation) {
            $deployment = ShiftWorkforceDeployment::where('shift_plan_id', $shift->id)
                ->where('assigned_machine_id', $allocation->id)
                ->where('status', 'active')
                ->with('employee')
                ->first();
            $operatorName = $deployment && $deployment->employee ? $deployment->employee->name : 'N/A';

            $actualBcm = round((float) DispatchTrip::where('shift_plan_id', $shift->id)
                ->where('excavator_equipment_id', $allocation->equipment_name_id)
                ->sum('quantity_bcm'), 2);

            $efficiency = 0.00;
            if ($targetBcmPerExcavator > 0) {
                $efficiency = round(($actualBcm / $targetBcmPerExcavator) * 100, 2);
            }

            $hasBreakdown = BreakdownTicket::where('equipment_allocation_id', $allocation->id)
                ->whereIn('status', ['open', 'in_progress', 'on_hold'])
                ->exists();
            $status = $hasBreakdown ? 'BREAKDOWN' : 'ACTIVE';

            $excavatorPerformance[] = [
                'asset_id' => $allocation->equipmentName->equipment_name ?? 'Unknown',
                'operator' => $operatorName,
                'target_bcm' => $targetBcmPerExcavator,
                'actual_bcm' => $actualBcm,
                'efficiency' => $efficiency,
                'status' => $status,
            ];
        }

        // Dumper Performance List
        $dumperPerformance = [];
        $dumperAllocations = $allocations->filter(function ($allocation) {
            $catName = $allocation->equipmentName && $allocation->equipmentName->equipment
                ? $allocation->equipmentName->equipment->name
                : '';
            return stripos($catName, 'dumper') !== false || $allocation->parent_equipment_id !== null;
        })->values();

        foreach ($dumperAllocations as $allocation) {
            $tripsCount = DispatchTrip::where('shift_plan_id', $shift->id)
                ->where('dumper_equipment_id', $allocation->equipment_name_id)
                ->count();

            $avgCycleTime = round((float) DispatchTrip::where('shift_plan_id', $shift->id)
                ->where('dumper_equipment_id', $allocation->equipment_name_id)
                ->avg('cycle_time_minutes'), 1);

            $totalQty = round((float) DispatchTrip::where('shift_plan_id', $shift->id)
                ->where('dumper_equipment_id', $allocation->equipment_name_id)
                ->sum('quantity_bcm'), 2);

            $hasBreakdown = BreakdownTicket::where('equipment_allocation_id', $allocation->id)
                ->whereIn('status', ['open', 'in_progress', 'on_hold'])
                ->exists();

            $status = 'ACTIVE';
            if ($hasBreakdown) {
                $status = 'BREAKDOWN';
            } else {
                $hasDelay = Delay::where('shift_plan_id', $shift->id)
                    ->where('equipment_name_id', $allocation->equipment_name_id)
                    ->whereNull('end_time')
                    ->exists();
                if ($hasDelay) {
                    $status = 'DELAYED';
                }
            }

            $dumperPerformance[] = [
                'dumper_id' => $allocation->equipmentName->equipment_name ?? 'Unknown',
                'cycles_trips' => $tripsCount,
                'avg_cycle_time_minutes' => $avgCycleTime,
                'total_quantity_bcm' => $totalQty,
                'status' => $status,
            ];
        }

        return [
            'total_equipment_allocated' => $totalAllocated,
            'equipment_utilized' => $equipmentUtilized,
            'equipment_availability_percent' => $equipmentAvailabilityPercent,
            'total_downtime_hours' => $totalDowntimeHours,
            'top_performing_equipment' => $topPerformingEquipment,
            'excavator_performance' => $excavatorPerformance,
            'dumper_performance' => $dumperPerformance,
        ];
    }

    /**
     * Build the workforce summary.
     */
    private function buildWorkforceSummary(ShiftPlan $shift): array
    {
        $totalEmployeesDeployed = ShiftWorkforceDeployment::where('shift_plan_id', $shift->id)
            ->active()
            ->count();

        $borrowedEmployeesCount = ShiftWorkforceDeployment::where('shift_plan_id', $shift->id)
            ->active()
            ->borrowed()
            ->count();

        return [
            'total_employees_deployed' => $totalEmployeesDeployed,
            'borrowed_employees_count' => $borrowedEmployeesCount,
        ];
    }

    /**
     * Build the fuel summary.
     */
    private function buildFuelSummary(ShiftPlan $shift): array
    {
        $totalFuelConsumed = round((float) FuelEntry::where('shift_plan_id', $shift->id)->sum('fuel_consumption'), 2);

        return [
            'total_fuel_consumed' => $totalFuelConsumed,
        ];
    }

    /**
     * Build the delay summary.
     */
    private function buildDelaySummary(ShiftPlan $shift): array
    {
        $totalDelayMinutes = (int) Delay::where('shift_plan_id', $shift->id)->sum('duration_minutes');
        $delayCount = Delay::where('shift_plan_id', $shift->id)->count();

        $topDelay = Delay::where('shift_plan_id', $shift->id)
            ->select('delay_category_id')
            ->selectRaw('SUM(duration_minutes) as total_duration')
            ->groupBy('delay_category_id')
            ->orderByDesc('total_duration')
            ->first();

        $topDelayReason = null;
        if ($topDelay && $topDelay->delay_category_id) {
            $category = DelayCategory::find($topDelay->delay_category_id);
            $topDelayReason = $category ? $category->delay_category : null;
        }

        return [
            'total_delay_minutes' => $totalDelayMinutes,
            'total_delay_hours' => round($totalDelayMinutes / 60, 2),
            'delay_count' => $delayCount,
            'top_delay_reason' => $topDelayReason,
        ];
    }

    /**
     * Build the breakdown summary.
     */
    private function buildBreakdownSummary(ShiftPlan $shift): array
    {
        $allocationIds = ShiftEquipmentAllocation::where('shift_plan_id', $shift->id)
            ->pluck('id')
            ->toArray();

        if (empty($allocationIds)) {
            return [
                'breakdown_count' => 0,
                'total_downtime_minutes' => 0,
                'total_downtime_hours' => 0.00,
            ];
        }

        $breakdownCount = BreakdownTicket::whereIn('equipment_allocation_id', $allocationIds)->count();
        $totalDowntimeMinutes = (int) BreakdownTicket::whereIn('equipment_allocation_id', $allocationIds)
            ->sum('downtime_minutes');

        return [
            'breakdown_count' => $breakdownCount,
            'total_downtime_minutes' => $totalDowntimeMinutes,
            'total_downtime_hours' => round($totalDowntimeMinutes / 60, 2),
        ];
    }

    /**
     * Build the safety summary.
     */
    private function buildSafetySummary(ShiftPlan $shift): array
    {
        $count = \App\Models\Incident::where('incident_date', $shift->planning_date)
            ->where('shift_id', $shift->shift_id)
            ->where('location_id', $shift->site_id)
            ->count();

        return [
            'incident_count' => $count,
            'status' => $count === 0 ? 'SAFE' : 'UNSAFE',
        ];
    }

    /**
     * Build the fleet performance summary.
     */
    private function buildFleetPerformance(ShiftPlan $shift): array
    {
        $utilizedDumperIds = DispatchTrip::where('shift_plan_id', $shift->id)
            ->whereNotNull('dumper_equipment_id')
            ->distinct()
            ->pluck('dumper_equipment_id')
            ->toArray();

        $activeDumpersCount = count($utilizedDumperIds);

        $avgCycleTime = round((float) DispatchTrip::where('shift_plan_id', $shift->id)->avg('cycle_time_minutes'), 1);

        $avgPayloadBcm = round((float) DispatchTrip::where('shift_plan_id', $shift->id)->avg('quantity_bcm'), 2);

        return [
            'active_dumpers_count' => $activeDumpersCount,
            'haul_cycle_avg_minutes' => $avgCycleTime,
            'payload_avg_bcm' => $avgPayloadBcm,
        ];
    }
}
