<?php

namespace App\Services;

use App\Models\DispatchTrip;
use App\Models\DispatchTripAudit;
use App\Models\ShiftPlan;
use App\Models\ShiftEquipmentAllocation;
use App\Exceptions\CompletedShiftOverrideException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Carbon\Carbon;

class DispatchTripService
{
    /**
     * 1. Log a new trip record.
     *
     * @param array $data
     * @param int $userId
     * @return DispatchTrip
     */
    public function logTrip(array $data, $userId)
    {
        return DB::transaction(function () use ($data, $userId) {
            // (a) Generate trip_reference_no: TRP-{current year}-{6-digit sequence}
            $currentYear = Carbon::now()->year;
            $maxReference = DispatchTrip::where('trip_reference_no', 'like', "TRP-{$currentYear}-%")
                ->lockForUpdate()
                ->max('trip_reference_no');

            $nextSeq = 1;
            if ($maxReference) {
                $parts = explode('-', $maxReference);
                if (count($parts) === 3) {
                    $nextSeq = intval($parts[2]) + 1;
                }
            }
            $tripReferenceNo = sprintf('TRP-%d-%06d', $currentYear, $nextSeq);

            $shiftPlan = ShiftPlan::with('shift')->findOrFail($data['shift_plan_id']);

            // Resolve shift_id if not present
            if (!isset($data['shift_id']) || empty($data['shift_id'])) {
                $data['shift_id'] = $shiftPlan->shift_id;
            }

            // Resolve start_time and end_time if they are in H:i:s format
            if (isset($data['start_time']) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $data['start_time'])) {
                $data['start_time'] = self::resolveDateTimeFromTime($data['start_time'], $shiftPlan);
            }
            if (isset($data['end_time']) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $data['end_time'])) {
                $data['end_time'] = self::resolveDateTimeFromTime($data['end_time'], $shiftPlan);
            }

            // Resolve trip_date_time if not present
            if (!isset($data['trip_date_time']) || empty($data['trip_date_time'])) {
                $data['trip_date_time'] = $data['start_time'] ?? Carbon::now()->toDateTimeString();
            }

            // (b) Calculate cycle_time_minutes
            $cycleTime = DispatchTrip::calculateCycleTime($data['start_time'], $data['end_time']);

            // (c) Create trip record
            $trip = DispatchTrip::create(array_merge($data, [
                'trip_reference_no' => $tripReferenceNo,
                'cycle_time_minutes' => $cycleTime,
                'created_by' => $userId,
                'status' => 'logged',
            ]));

            // (d) Recalculate shift plan actual bcm
            $this->recalculateShiftPlanActualBcm($trip->shift_plan_id);

            // Recalculate fuel entries for the excavator and dumper
            $fuelService = resolve(\App\Services\FuelService::class);
            if ($trip->excavator_equipment_id) {
                $fuelService->recalculateFuelEntryBcm($trip->shift_plan_id, $trip->excavator_equipment_id);
            }
            if ($trip->dumper_equipment_id) {
                $fuelService->recalculateFuelEntryBcm($trip->shift_plan_id, $trip->dumper_equipment_id);
            }

            return $trip;
        });
    }

    /**
     * Helper to recalculate actual BCM for a given shift plan.
     *
     * @param int $shiftPlanId
     * @return void
     */
    protected function recalculateShiftPlanActualBcm($shiftPlanId)
    {
        $totalBcm = DispatchTrip::where('shift_plan_id', $shiftPlanId)->sum('quantity_bcm');
        $shiftPlan = ShiftPlan::find($shiftPlanId);
        if ($shiftPlan) {
            $shiftPlan->update(['actual_bcm' => $totalBcm]);
        }
    }

    /**
     * 2. Retrieve dashboard KPI cards, trend chart, top performers, and recent activity.
     *
     * @param array $filters
     * @return array
     */
    public function getDashboard(array $filters)
    {
        $query = DispatchTrip::query();

        // Apply filters
        $this->applyFilters($query, $filters);

        // 1. KPI cards
        $kpiQuery = clone $query;
        $totalTrips = $kpiQuery->count();
        $totalQuantityBcm = $kpiQuery->sum('quantity_bcm');
        $averageCycleTimeMinutes = $kpiQuery->avg('cycle_time_minutes');
        $activeDumpers = $kpiQuery->distinct()->count('dumper_equipment_id');

        $kpiCards = [
            'total_trips' => (int) $totalTrips,
            'total_quantity_bcm' => round((float) $totalQuantityBcm, 2),
            'average_cycle_time_minutes' => round((float) $averageCycleTimeMinutes, 2),
            'active_dumpers' => (int) $activeDumpers,
        ];

        // 2. fleet_productivity_trend
        $dateFrom = isset($filters['date_from']) ? Carbon::parse($filters['date_from']) : Carbon::today()->startOfDay();
        $dateTo = isset($filters['date_to']) ? Carbon::parse($filters['date_to']) : Carbon::today()->endOfDay();
        $spanHours = $dateFrom->diffInHours($dateTo);

        if ($spanHours <= 24) {
            $trendData = (clone $query)
                ->selectRaw("DATE_FORMAT(start_time, '%Y-%m-%d %H:00') as time_bucket")
                ->selectRaw("SUM(quantity_bcm) as total_quantity_bcm")
                ->groupBy('time_bucket')
                ->orderBy('time_bucket', 'ASC')
                ->get();
        } else {
            $trendData = (clone $query)
                ->selectRaw("DATE_FORMAT(start_time, '%Y-%m-%d') as time_bucket")
                ->selectRaw("SUM(quantity_bcm) as total_quantity_bcm")
                ->groupBy('time_bucket')
                ->orderBy('time_bucket', 'ASC')
                ->get();
        }

        $fleetProductivityTrend = $trendData->map(function ($row) {
            return [
                'time_bucket' => $row->time_bucket,
                'total_quantity_bcm' => round((float) $row->total_quantity_bcm, 2),
            ];
        })->toArray();

        // 3. top_performing_dumpers (top 5 by quantity)
        $topDumpersData = (clone $query)
            ->select('dumper_equipment_id', 'equipment_names.equipment_name as dumper_number')
            ->selectRaw('SUM(quantity_bcm) as total_quantity_bcm')
            ->join('equipment_names', 'equipment_names.id', '=', 'dispatch_trips.dumper_equipment_id')
            ->groupBy('dumper_equipment_id', 'equipment_names.equipment_name')
            ->orderBy('total_quantity_bcm', 'DESC')
            ->limit(5)
            ->get();

        $topPerformingDumpers = $topDumpersData->map(function ($row) {
            return [
                'dumper_equipment_id' => $row->dumper_equipment_id,
                'dumper_number' => $row->dumper_number,
                'total_quantity_bcm' => round((float) $row->total_quantity_bcm, 2),
            ];
        })->toArray();

        // 4. recent_trip_activity (latest 10 trips)
        $recentTrips = (clone $query)
            ->with([
                'dumper:id,equipment_name',
                'excavator:id,equipment_name',
                'driver:id,name',
                'loadingPoint:id,name',
                'dumpingPoint:id,name',
                'site:id,site_name',
                'shiftPlan:id,planning_date,shift_id',
                'shiftPlan.shift:id,shift_name',
            ])
            ->orderBy('start_time', 'DESC')
            ->limit(10)
            ->get()
            ->map(function ($trip) {
                return [
                    'id' => $trip->id,
                    'trip_reference_no' => $trip->trip_reference_no,
                    'start_time' => $trip->start_time ? $trip->start_time->format('Y-m-d H:i:s') : null,
                    'end_time' => $trip->end_time ? $trip->end_time->format('Y-m-d H:i:s') : null,
                    'dumper_number' => optional($trip->dumper)->equipment_name,
                    'excavator_number' => optional($trip->excavator)->equipment_name,
                    'driver_name' => optional($trip->driver)->name,
                    'loading_point_name' => optional($trip->loadingPoint)->name,
                    'dumping_point_name' => optional($trip->dumpingPoint)->name,
                    'quantity_bcm' => round((float) $trip->quantity_bcm, 2),
                ];
            })->toArray();

        return [
            'kpi_cards' => $kpiCards,
            'fleet_productivity_trend' => $fleetProductivityTrend,
            'top_performing_dumpers' => $topPerformingDumpers,
            'recent_trip_activity' => $recentTrips,
        ];
    }

    /**
     * 3. Paginated list of trips with summary.
     *
     * @param array $filters
     * @param int $perPage
     * @return array
     */
    public function getRegister(array $filters, $perPage = 15)
    {
        $query = DispatchTrip::query();

        // Apply filters
        $this->applyFilters($query, $filters);

        if (isset($filters['trip_reference_no'])) {
            $query->where('trip_reference_no', 'like', '%' . $filters['trip_reference_no'] . '%');
        }

        // Calculate KPI summary against the SAME filtered query
        $summaryQuery = clone $query;
        $totalTrips = $summaryQuery->count();
        $totalQuantityBcm = $summaryQuery->sum('quantity_bcm');
        $averageCycleTimeMinutes = $summaryQuery->avg('cycle_time_minutes');
        $activeDumpers = $summaryQuery->distinct()->count('dumper_equipment_id');

        $summary = [
            'total_trips' => (int) $totalTrips,
            'total_quantity_bcm' => round((float) $totalQuantityBcm, 2),
            'average_cycle_time_minutes' => round((float) $averageCycleTimeMinutes, 2),
            'active_dumpers' => (int) $activeDumpers,
        ];

        // Fetch paginated results
        $records = $query->with([
            'dumper:id,equipment_name',
            'excavator:id,equipment_name',
            'driver:id,name',
            'loadingPoint:id,name',
            'dumpingPoint:id,name',
            'site:id,site_name',
            'shiftPlan:id,planning_date,shift_id',
            'shiftPlan.shift:id,shift_name',
        ])
        ->orderBy('start_time', 'DESC')
        ->paginate($perPage);

        return [
            'records' => $records,
            'summary' => $summary,
        ];
    }

    /**
     * 4. Retrieve single trip details.
     *
     * @param int $id
     * @return array
     */
    public function getTripDetails($id)
    {
        $trip = DispatchTrip::with([
            'dumper:id,equipment_name',
            'excavator:id,equipment_name',
            'driver:id,name',
            'loadingPoint:id,name',
            'dumpingPoint:id,name',
            'site:id,site_name',
            'shiftPlan:id,planning_date,shift_id',
            'shiftPlan.shift:id,shift_name',
            'audits.user:id,name',
        ])->findOrFail($id);

        return [
            'trip_info' => [
                'id' => $trip->id,
                'trip_reference_no' => $trip->trip_reference_no,
                'shift_plan_id' => $trip->shift_plan_id,
                'shift_id' => $trip->shift_id,
                'trip_date_time' => $trip->trip_date_time ? $trip->trip_date_time->format('Y-m-d H:i:s') : null,
                'start_time' => $trip->start_time ? $trip->start_time->format('Y-m-d H:i:s') : null,
                'end_time' => $trip->end_time ? $trip->end_time->format('Y-m-d H:i:s') : null,
                'cycle_time_minutes' => round((float) $trip->cycle_time_minutes, 2),
                'quantity_bcm' => round((float) $trip->quantity_bcm, 2),
                'distance_meters' => !is_null($trip->distance_meters) ? round((float) $trip->distance_meters, 2) : null,
                'status' => $trip->status,
                'created_at' => $trip->created_at ? $trip->created_at->format('Y-m-d H:i:s') : null,
            ],
            'equipment_info' => [
                'dumper' => [
                    'id' => optional($trip->dumper)->id,
                    'machine_number' => optional($trip->dumper)->equipment_name,
                ],
                'excavator' => [
                    'id' => optional($trip->excavator)->id,
                    'machine_number' => optional($trip->excavator)->equipment_name,
                ],
                'driver' => [
                    'id' => optional($trip->driver)->id,
                    'name' => optional($trip->driver)->name,
                ],
            ],
            'route_info' => [
                'loading_point' => [
                    'id' => optional($trip->loadingPoint)->id,
                    'name' => optional($trip->loadingPoint)->name,
                ],
                'dumping_point' => [
                    'id' => optional($trip->dumpingPoint)->id,
                    'name' => optional($trip->dumpingPoint)->name,
                ],
            ],
            'operational_info' => [
                'site' => [
                    'id' => optional($trip->site)->id,
                    'name' => optional($trip->site)->site_name,
                ],
                'shift_plan' => [
                    'planning_date' => optional($trip->shiftPlan)->planning_date ? optional($trip->shiftPlan)->planning_date->format('Y-m-d') : null,
                    'shift_name' => optional(optional($trip->shiftPlan)->shift)->shift_name,
                ],
            ],
            'audit_info' => $trip->audits->map(function ($audit) {
                return [
                    'changed_fields' => $audit->changed_fields,
                    'changed_by_name' => optional($audit->user)->name,
                    'changed_at' => $audit->changed_at ? $audit->changed_at->format('Y-m-d H:i:s') : null,
                ];
            })->toArray(),
        ];
    }

    /**
     * 5. Update a trip record.
     *
     * @param int $id
     * @param array $data
     * @param int $userId
     * @return DispatchTrip
     * @throws CompletedShiftOverrideException
     */
    public function updateTrip($id, array $data, $userId)
    {
        return DB::transaction(function () use ($id, $data, $userId) {
            $trip = DispatchTrip::with('shiftPlan.shift')->findOrFail($id);

            // Check if shift is completed or closed
            if (optional($trip->shiftPlan)->status === 'completed' || optional($trip->shiftPlan)->status === 'closed') {
                if (!Gate::allows('override-locked-shift-data')) {
                    throw new CompletedShiftOverrideException("You do not have permission to modify data for a completed shift.");
                }
            }

            // Resolve start_time and end_time if they are in H:i:s format
            if (isset($data['start_time']) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $data['start_time'])) {
                $data['start_time'] = self::resolveDateTimeFromTime($data['start_time'], $trip->shiftPlan);
            }
            if (isset($data['end_time']) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $data['end_time'])) {
                $data['end_time'] = self::resolveDateTimeFromTime($data['end_time'], $trip->shiftPlan);
            } else if (!isset($data['end_time']) && isset($data['start_time'])) {
                if ($trip->end_time) {
                    $planningDate = Carbon::parse($trip->shiftPlan->planning_date)->format('Y-m-d');
                    $endTimeOnly = $trip->end_time->format('H:i:s');
                    $endDateTime = Carbon::parse($planningDate . ' ' . $endTimeOnly);
                    if ($endDateTime->lte(Carbon::parse($data['start_time']))) {
                        $endDateTime->addDay();
                    }
                    $data['end_time'] = $endDateTime->toDateTimeString();
                }
            }

            // Snapshot old values
            $oldValues = $trip->toArray();

            // Handle cycle time recalculation if times changed
            $timeChanged = false;
            if (isset($data['start_time']) && $data['start_time'] !== $trip->start_time->format('Y-m-d H:i:s')) {
                $timeChanged = true;
            }
            if (isset($data['end_time']) && $data['end_time'] !== $trip->end_time->format('Y-m-d H:i:s')) {
                $timeChanged = true;
            }

            // Fill and calculate updated fields
            $trip->fill($data);

            if ($timeChanged) {
                $trip->cycle_time_minutes = DispatchTrip::calculateCycleTime($trip->start_time, $trip->end_time);
            }

            $trip->updated_by = $userId;

            // Generate diff for changed fields
            $changedFields = [];
            foreach ($trip->getDirty() as $field => $newValue) {
                if (in_array($field, ['updated_at', 'updated_by'])) {
                    continue;
                }
                $oldVal = isset($oldValues[$field]) ? $oldValues[$field] : null;
                $changedFields[$field] = [$oldVal, $newValue];
            }

            $trip->save();

            // Create audit log if fields changed
            if (!empty($changedFields)) {
                DispatchTripAudit::create([
                    'dispatch_trip_id' => $trip->id,
                    'changed_fields' => $changedFields,
                    'changed_by' => $userId,
                    'changed_at' => Carbon::now(),
                ]);
            }

            // Recalculate shift plan actual bcm
            $this->recalculateShiftPlanActualBcm($trip->shift_plan_id);

            // Recalculate fuel entries for old and new excavator/dumper machine IDs
            $fuelService = resolve(\App\Services\FuelService::class);
            
            $oldExcavatorId = $oldValues['excavator_equipment_id'] ?? null;
            $oldDumperId = $oldValues['dumper_equipment_id'] ?? null;
            $oldShiftPlanId = $oldValues['shift_plan_id'] ?? null;

            if ($oldShiftPlanId) {
                if ($oldExcavatorId) {
                    $fuelService->recalculateFuelEntryBcm($oldShiftPlanId, $oldExcavatorId);
                }
                if ($oldDumperId) {
                    $fuelService->recalculateFuelEntryBcm($oldShiftPlanId, $oldDumperId);
                }
            }

            if ($trip->excavator_equipment_id) {
                $fuelService->recalculateFuelEntryBcm($trip->shift_plan_id, $trip->excavator_equipment_id);
            }
            if ($trip->dumper_equipment_id) {
                $fuelService->recalculateFuelEntryBcm($trip->shift_plan_id, $trip->dumper_equipment_id);
            }

            return $trip;
        });
    }

    /**
     * 6. Retrieve summary per dumper.
     *
     * @param array $filters
     * @return array
     */
    public function getDumperSummary(array $filters)
    {
        $query = DispatchTrip::query();

        // Apply filters
        $this->applyFilters($query, $filters);

        // Fetch aggregation group by dumper
        $dumperSummaryData = (clone $query)
            ->select('dumper_equipment_id', 'equipment_names.equipment_name as dumper_number')
            ->selectRaw('COUNT(dispatch_trips.id) as total_trips')
            ->selectRaw('SUM(quantity_bcm) as total_quantity_bcm')
            ->selectRaw('AVG(cycle_time_minutes) as average_cycle_time_minutes')
            ->selectRaw('SUM(distance_meters) as distance_covered_meters')
            ->join('equipment_names', 'equipment_names.id', '=', 'dispatch_trips.dumper_equipment_id')
            ->groupBy('dumper_equipment_id', 'equipment_names.equipment_name')
            ->get();

        $bestDumper = null;
        $highestTripDumper = null;
        $fastestDumper = null;

        $maxQty = -1;
        $maxTrips = -1;
        $minCycleTime = 99999999;

        $formattedDumpers = [];

        foreach ($dumperSummaryData as $row) {
            $totalQty = (float) $row->total_quantity_bcm;
            $trips = (int) $row->total_trips;
            $avgCycle = (float) $row->average_cycle_time_minutes;
            $dist = (float) $row->distance_covered_meters;
            $prodPerTrip = $trips > 0 ? round($totalQty / $trips, 2) : 0;

            $formattedRow = [
                'dumper_equipment_id' => $row->dumper_equipment_id,
                'dumper_number' => $row->dumper_number,
                'total_trips' => $trips,
                'total_quantity_bcm' => round($totalQty, 2),
                'average_cycle_time_minutes' => round($avgCycle, 2),
                'productivity_per_trip_bcm' => $prodPerTrip,
                'distance_covered_meters' => round($dist, 2),
            ];

            $formattedDumpers[] = $formattedRow;

            // Calculate KPIs
            if ($totalQty > $maxQty) {
                $maxQty = $totalQty;
                $bestDumper = [
                    'dumper_equipment_id' => $row->dumper_equipment_id,
                    'dumper_number' => $row->dumper_number,
                    'value' => round($totalQty, 2),
                ];
            }

            if ($trips > $maxTrips) {
                $maxTrips = $trips;
                $highestTripDumper = [
                    'dumper_equipment_id' => $row->dumper_equipment_id,
                    'dumper_number' => $row->dumper_number,
                    'value' => $trips,
                ];
            }

            if ($avgCycle < $minCycleTime && $avgCycle > 0) {
                $minCycleTime = $avgCycle;
                $fastestDumper = [
                    'dumper_equipment_id' => $row->dumper_equipment_id,
                    'dumper_number' => $row->dumper_number,
                    'value' => round($avgCycle, 2),
                ];
            }
        }

        return [
            'kpi_cards' => [
                'best_performing_dumper' => $bestDumper,
                'highest_trip_count_dumper' => $highestTripDumper,
                'fastest_dumper' => $fastestDumper,
            ],
            'dumpers' => $formattedDumpers,
        ];
    }

    /**
     * 7. Retrieve comprehensive fleet performance.
     *
     * @param array $filters
     * @return array
     */
    public function getFleetPerformance(array $filters)
    {
        $query = DispatchTrip::query();

        // Apply filters
        $this->applyFilters($query, $filters);

        // Fetch dumper list and core statistics
        $dumperSummaryData = (clone $query)
            ->select('dumper_equipment_id', 'equipment_names.equipment_name as dumper_number')
            ->selectRaw('COUNT(dispatch_trips.id) as total_trips')
            ->selectRaw('SUM(quantity_bcm) as total_quantity_bcm')
            ->selectRaw('AVG(cycle_time_minutes) as average_cycle_time_minutes')
            ->selectRaw('SUM(distance_meters) as distance_covered_meters')
            ->join('equipment_names', 'equipment_names.id', '=', 'dispatch_trips.dumper_equipment_id')
            ->groupBy('dumper_equipment_id', 'equipment_names.equipment_name')
            ->get();

        $activeDumpers = $dumperSummaryData->count();
        $totalQuantityBcm = $dumperSummaryData->sum('total_quantity_bcm');

        // Resolve assigned dumper count for utilization KPI
        $assignedDumpersCount = null;
        if (isset($filters['shift_plan_id'])) {
            $assignedDumpersCount = ShiftEquipmentAllocation::where('shift_plan_id', $filters['shift_plan_id'])
                ->where(function ($q) {
                    $q->whereNotNull('parent_equipment_id')
                        ->orWhereHas('equipmentName.equipment', function ($sub) {
                            $sub->where('name', 'like', '%dumper%');
                        });
                })
                ->count();
        }

        $fleetUtilizationPercent = null;
        if ($assignedDumpersCount !== null && $assignedDumpersCount > 0) {
            $fleetUtilizationPercent = round(($activeDumpers / $assignedDumpersCount) * 100, 2);
        }

        // Resolve shift hours for throughput KPI
        $shiftHours = null;
        if (isset($filters['shift_plan_id'])) {
            $shiftPlan = ShiftPlan::with('shift')->find($filters['shift_plan_id']);
            if ($shiftPlan && $shiftPlan->shift) {
                $start = Carbon::parse($shiftPlan->shift->start_time);
                $end = Carbon::parse($shiftPlan->shift->end_time);
                if ($end->lt($start)) {
                    $end->addDay();
                }
                $shiftHours = $end->diffInMinutes($start) / 60;
            }
        }

        $fleetThroughput = null;
        if ($shiftHours !== null && $shiftHours > 0) {
            $fleetThroughput = round($totalQuantityBcm / $shiftHours, 2);
        }

        // Determine maximums for ranking scores
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

        // Compute scores and initial rows
        $formattedDumpers = [];
        foreach ($dumperSummaryData as $row) {
            $qty = (float) $row->total_quantity_bcm;
            $trips = (int) $row->total_trips;
            $avgCycle = (float) $row->average_cycle_time_minutes;
            $dist = (float) $row->distance_covered_meters;

            $quantityScore = ($qty / $maxQuantity) * 100;
            $tripScore = ($trips / $maxTrips) * 100;
            $cycleScore = $avgCycle > 0 ? ($minCycleTime / $avgCycle) * 100 : 0;

            // Note: Weightings are 50% Quantity, 25% Trip count, and 25% Cycle Time.
            $compositeScore = ($quantityScore * 0.5) + ($tripScore * 0.25) + ($cycleScore * 0.25);

            $formattedDumpers[] = [
                'dumper_equipment_id' => $row->dumper_equipment_id,
                'dumper_number' => $row->dumper_number,
                'total_trips' => $trips,
                'total_quantity_bcm' => round($qty, 2),
                'average_cycle_time_minutes' => round($avgCycle, 2),
                'productivity_per_trip_bcm' => $trips > 0 ? round($qty / $trips, 2) : 0,
                'distance_covered_meters' => round($dist, 2),
                'composite_score' => round($compositeScore, 2),
                'fleet_rank' => null,
            ];
        }

        // Apply dense ranking based on composite score descending
        usort($formattedDumpers, function ($a, $b) {
            return $b['composite_score'] <=> $a['composite_score'];
        });

        $rank = 0;
        $prevScore = -1;
        foreach ($formattedDumpers as &$dumper) {
            if ($dumper['composite_score'] !== $prevScore) {
                $rank++;
                $prevScore = $dumper['composite_score'];
            }
            $dumper['fleet_rank'] = $rank;
        }
        unset($dumper);

        // Chart 1: fleet_productivity_trend
        $dateFrom = isset($filters['date_from']) ? Carbon::parse($filters['date_from']) : Carbon::today()->startOfDay();
        $dateTo = isset($filters['date_to']) ? Carbon::parse($filters['date_to']) : Carbon::today()->endOfDay();
        $spanHours = $dateFrom->diffInHours($dateTo);

        if ($spanHours <= 24) {
            $trendData = (clone $query)
                ->selectRaw("DATE_FORMAT(start_time, '%Y-%m-%d %H:00') as time_bucket")
                ->selectRaw("SUM(quantity_bcm) as total_quantity_bcm")
                ->groupBy('time_bucket')
                ->orderBy('time_bucket', 'ASC')
                ->get();
        } else {
            $trendData = (clone $query)
                ->selectRaw("DATE_FORMAT(start_time, '%Y-%m-%d') as time_bucket")
                ->selectRaw("SUM(quantity_bcm) as total_quantity_bcm")
                ->groupBy('time_bucket')
                ->orderBy('time_bucket', 'ASC')
                ->get();
        }

        $fleetProductivityTrend = $trendData->map(function ($row) {
            return [
                'time_bucket' => $row->time_bucket,
                'total_quantity_bcm' => round((float) $row->total_quantity_bcm, 2),
            ];
        })->toArray();

        // Chart 2: dumper_productivity_comparison
        $dumperProductivityComparison = array_map(function ($item) {
            return [
                'dumper_equipment_id' => $item['dumper_equipment_id'],
                'dumper_number' => $item['dumper_number'],
                'total_quantity_bcm' => $item['total_quantity_bcm'],
            ];
        }, $formattedDumpers);

        // Chart 3: cycle_time_analysis
        $cycleTimeAnalysis = array_map(function ($item) {
            return [
                'dumper_equipment_id' => $item['dumper_equipment_id'],
                'dumper_number' => $item['dumper_number'],
                'average_cycle_time_minutes' => $item['average_cycle_time_minutes'],
            ];
        }, $formattedDumpers);

        return [
            'kpi_cards' => [
                'active_dumpers' => $activeDumpers,
                'assigned_dumpers_count' => $assignedDumpersCount,
                'fleet_utilization_percent' => $fleetUtilizationPercent,
                'fleet_throughput' => $fleetThroughput,
            ],
            'fleet_performance' => $formattedDumpers,
            'charts' => [
                'fleet_productivity_trend' => $fleetProductivityTrend,
                'dumper_productivity_comparison' => $dumperProductivityComparison,
                'cycle_time_analysis' => $cycleTimeAnalysis,
            ]
        ];
    }

    /**
     * Shared filter logic helper.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return void
     */
    protected function applyFilters($query, array $filters)
    {
        if (isset($filters['date_from'])) {
            $query->where('start_time', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->where('start_time', '<=', $filters['date_to']);
        }
        if (isset($filters['shift_plan_id'])) {
            $query->where('shift_plan_id', $filters['shift_plan_id']);
        }
        if (isset($filters['site_id'])) {
            $query->where('site_id', $filters['site_id']);
        }
        if (isset($filters['excavator_equipment_id'])) {
            $query->where('excavator_equipment_id', $filters['excavator_equipment_id']);
        }
        if (isset($filters['dumper_equipment_id'])) {
            $query->where('dumper_equipment_id', $filters['dumper_equipment_id']);
        }
        if (isset($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }
        if (isset($filters['loading_point_id'])) {
            $query->where('loading_point_id', $filters['loading_point_id']);
        }
        if (isset($filters['dumping_point_id'])) {
            $query->where('dumping_point_id', $filters['dumping_point_id']);
        }
    }

    /**
     * Resolve a time-only string (H:i:s) to a full datetime string based on shift plan.
     *
     * @param string $time
     * @param ShiftPlan $shiftPlan
     * @return string
     */
    public static function resolveDateTimeFromTime($time, ShiftPlan $shiftPlan)
    {
        $planningDate = Carbon::parse($shiftPlan->planning_date)->format('Y-m-d');
        $shift = $shiftPlan->shift;

        if (!$shift) {
            return $planningDate . ' ' . $time;
        }

        $shiftStart = $shift->start_time;
        $shiftEnd = $shift->end_time;

        // Check if the shift crosses midnight
        $crossesMidnight = $shiftEnd <= $shiftStart;

        if ($crossesMidnight) {
            // If the time is chronologically before the shift start time, it belongs to the next day.
            if ($time < $shiftStart) {
                return Carbon::parse($planningDate . ' ' . $time)->addDay()->toDateTimeString();
            }
        }

        return $planningDate . ' ' . $time;
    }
}
