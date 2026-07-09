<?php

namespace App\Services;

use App\Models\DispatchTrip;
use App\Models\ShiftPlan;
use App\Models\BreakdownTicket;
use App\Models\FuelEntry;
use App\Models\ShiftEquipmentAllocation;
use App\Models\ShiftWorkforceDeployment;
use App\Models\Delay;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OverviewDashboardService
{
    /**
     * Build overview dashboard KPIs and charts.
     *
     * @param array $filters
     * @return array
     */
    public function build(array $filters): array
    {
        $siteId = $filters['mine_site_id'];
        $blockId = $filters['block_id'];
        $shiftId = $filters['shift_id'];
        $from = $filters['from'];
        $to = $filters['to'];
        $machineTypeId = $filters['machine_type_id'] ?? null;
        $machineNumberId = $filters['machine_number_id'] ?? null;

        // 1. Dispatch stats (single aggregate pass)
        $dispatch = DispatchTrip::when($siteId, function ($q) use ($siteId) {
                return $q->where('mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where(function ($sub) use ($machineTypeId) {
                    $sub->whereHas('dumper', function ($eq) use ($machineTypeId) {
                        $eq->where('equipment_id', $machineTypeId);
                    })->orWhereHas('excavator', function ($eq) use ($machineTypeId) {
                        $eq->where('equipment_id', $machineTypeId);
                    });
                });
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where(function ($sub) use ($machineNumberId) {
                    $sub->where('dumper_equipment_id', $machineNumberId)
                        ->orWhere('excavator_equipment_id', $machineNumberId);
                });
            })
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(total_cycles), 0) as total_trips,
                COALESCE(SUM(quantity_bcm), 0) as total_bcm,
                COUNT(DISTINCT dumper_equipment_id) as active_dumpers
            ')
            ->first();

        $totalTrips = (int) $dispatch->total_trips;
        $actualBcm = (float) $dispatch->total_bcm;
        $activeDumpers = (int) $dispatch->active_dumpers;

        // 2. Target BCM from shift plans
        $plans = ShiftPlan::when($siteId, function ($q) use ($siteId) {
                return $q->where('site_id', $siteId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->whereBetween('planning_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(target_bcm), 0) as total_target_bcm')
            ->first();

        $targetBcm = (float) $plans->total_target_bcm;

        // 3. Breakdowns
        $breakdowns = BreakdownTicket::when($siteId, function ($q) use ($siteId) {
                return $q->where('mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('equipment_name_id', $machineNumberId);
            })
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COUNT(*) as count,
                COALESCE(SUM(downtime_minutes), 0) as total_downtime_minutes
            ')
            ->first();

        $breakdownsCount = (int) $breakdowns->count;
        $downtimeHours = round((float) $breakdowns->total_downtime_minutes / 60, 2);

        // 4. Fuel consumed
        $fuel = FuelEntry::when($siteId, function ($q) use ($siteId) {
                return $q->where('mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('equipment_name_id', $machineNumberId);
            })
            ->whereBetween('date', [$from, $to])
            ->selectRaw('COALESCE(SUM(fuel_consumption), 0) as total_consumed')
            ->first();

        $fuelConsumed = (float) $fuel->total_consumed;

        // KPI formulas
        $fuelEfficiency = $actualBcm > 0 ? round($fuelConsumed / $actualBcm, 2) : 0.00;

        // OB Milestone Progress
        $progressPercent = $targetBcm > 0 ? round(($actualBcm / $targetBcm) * 100, 2) : 0.00;

        // Equipment Health (availability percent)
        // Estimating period hours: total allocated * hours
        $allocatedCountQuery = DB::table('shift_equipment_allocations')
            ->join('shift_plans', 'shift_equipment_allocations.shift_plan_id', '=', 'shift_plans.id')
            ->when($siteId, function ($q) use ($siteId) {
                return $q->where('shift_plans.site_id', $siteId);
            })
            ->whereBetween('shift_plans.planning_date', [$from, $to]);

        if ($machineNumberId) {
            $allocatedCountQuery->where('shift_equipment_allocations.equipment_name_id', $machineNumberId);
        }
        if ($machineTypeId) {
            $allocatedCountQuery->join('equipment_names', 'shift_equipment_allocations.equipment_name_id', '=', 'equipment_names.id')
                ->where('equipment_names.equipment_id', $machineTypeId);
        }

        $allocatedCount = $allocatedCountQuery->count();

        $allocatedCount = max($allocatedCount, 1);
        $periodHours = $allocatedCount * 8.00;
        $equipmentAvailabilityPercent = round((($periodHours - $downtimeHours) / $periodHours) * 100, 2);
        $equipmentAvailabilityPercent = max(0.00, min(100.00, $equipmentAvailabilityPercent));

        // Charts: Production vs Target Trend
        $prodTrend = DispatchTrip::when($siteId, function ($q) use ($siteId) {
                return $q->where('mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where(function ($sub) use ($machineTypeId) {
                    $sub->whereHas('dumper', function ($eq) use ($machineTypeId) {
                        $eq->where('equipment_id', $machineTypeId);
                    })->orWhereHas('excavator', function ($eq) use ($machineTypeId) {
                        $eq->where('equipment_id', $machineTypeId);
                    });
                });
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where(function ($sub) use ($machineNumberId) {
                    $sub->where('dumper_equipment_id', $machineNumberId)
                        ->orWhere('excavator_equipment_id', $machineNumberId);
                });
            })
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw('date, COALESCE(SUM(quantity_bcm), 0) as actual')
            ->get()
            ->mapWithKeys(function ($item) {
                $dateStr = $item->date ? Carbon::parse($item->date)->toDateString() : '';
                return [$dateStr => $item->actual];
            });

        $targetTrend = ShiftPlan::when($siteId, function ($q) use ($siteId) {
                return $q->where('site_id', $siteId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->whereBetween('planning_date', [$from, $to])
            ->groupBy('planning_date')
            ->orderBy('planning_date')
            ->selectRaw('planning_date as date, COALESCE(SUM(target_bcm), 0) as target')
            ->get()
            ->mapWithKeys(function ($item) {
                $dateStr = $item->date ? Carbon::parse($item->date)->toDateString() : '';
                return [$dateStr => $item->target];
            });

        $trendDates = [];
        $trendActual = [];
        $trendTarget = [];

        $startDate = Carbon::parse($from);
        $endDate = Carbon::parse($to);
        $curr = $startDate->copy();

        while ($curr->lte($endDate)) {
            $dateStr = $curr->toDateString();
            $trendDates[] = $dateStr;
            $trendActual[] = round((float) ($prodTrend[$dateStr] ?? 0.00), 2);
            $trendTarget[] = round((float) ($targetTrend[$dateStr] ?? 0.00), 2);
            $curr->addDay();
        }

        $shiftTargets = ShiftPlan::when($siteId, function ($q) use ($siteId) {
                return $q->where('site_id', $siteId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->whereBetween('planning_date', [$from, $to])
            ->join('shifts', 'shifts.id', '=', 'shift_plans.shift_id')
            ->groupBy('shifts.id', 'shifts.shift_name')
            ->selectRaw('shifts.shift_name, COALESCE(SUM(target_bcm), 0) as target')
            ->get()
            ->pluck('target', 'shift_name')
            ->toArray();

        // Shift Performance Comparison
        $shiftData = DispatchTrip::when($siteId, function ($q) use ($siteId) {
                return $q->where('mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where(function ($sub) use ($machineTypeId) {
                    $sub->whereHas('dumper', function ($eq) use ($machineTypeId) {
                        $eq->where('equipment_id', $machineTypeId);
                    })->orWhereHas('excavator', function ($eq) use ($machineTypeId) {
                        $eq->where('equipment_id', $machineTypeId);
                    });
                });
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where(function ($sub) use ($machineNumberId) {
                    $sub->where('dumper_equipment_id', $machineNumberId)
                        ->orWhere('excavator_equipment_id', $machineNumberId);
                });
            })
            ->whereBetween('date', [$from, $to])
            ->join('shifts', 'shifts.id', '=', 'dispatch_trips.shift_id')
            ->groupBy('shifts.shift_name')
            ->selectRaw('shifts.shift_name, COALESCE(SUM(quantity_bcm), 0) as actual')
            ->get();

        $shifts = [];
        $shiftActuals = [];
        $shiftTargetsList = [];
        $shiftPercentages = [];

        $allShiftNames = array_unique(array_merge(
            $shiftData->pluck('shift_name')->toArray(),
            array_keys($shiftTargets)
        ));

        foreach ($allShiftNames as $name) {
            $shifts[] = $name;
            $actual = 0.00;
            $matched = $shiftData->firstWhere('shift_name', $name);
            if ($matched) {
                $actual = round((float) $matched->actual, 2);
            }
            $target = round((float) ($shiftTargets[$name] ?? 0.00), 2);
            $percentage = $target > 0 ? round(($actual / $target) * 100, 2) : 0.00;

            $shiftActuals[] = $actual;
            $shiftTargetsList[] = $target;
            $shiftPercentages[] = $percentage;
        }

        return [
            'kpis' => [
                'production_today' => ['value' => round($actualBcm, 2), 'unit' => 'BCM'],
                'ob_removal'        => ['value' => round($actualBcm, 2), 'unit' => 'BCM'],
                'eqpt_running'      => ['value' => $activeDumpers],
                'breakdowns_count'  => ['value' => $breakdownsCount],
                'total_trips'       => ['value' => $totalTrips],
                'fuel_efficiency'   => ['value' => $fuelEfficiency, 'unit' => 'L/BCM'],
            ],
            'ob_milestone_progress' => [
                'target'     => round($targetBcm, 2),
                'actual'     => round($actualBcm, 2),
                'percentage' => $progressPercent,
            ],
            'equipment_health' => [
                'availability_percent' => $equipmentAvailabilityPercent,
                'downtime_hours'       => $downtimeHours,
            ],
            'charts' => [
                'production_vs_target' => [
                    'dates'  => $trendDates,
                    'actual' => $trendActual,
                    'target' => $trendTarget,
                ],
                'shift_performance' => [
                    'shifts'                 => $shifts,
                    'values'                 => $shiftActuals,
                    'actual'                 => $shiftActuals,
                    'target'                 => $shiftTargetsList,
                    'achievement_percentage' => $shiftPercentages,
                ],
                'fleet_productivity' => [
                    'trips'          => $totalTrips,
                    'quantity'       => round($actualBcm, 2),
                    'active_dumpers' => $activeDumpers,
                ],
            ],
            'top_performing_excavators' => $this->getTopPerformingExcavators($filters),
            'top_performing_dumpers'    => $this->getTopPerformingDumpers($filters),
            'shift_delay_analysis'      => $this->getShiftDelayAnalysis($filters),
        ];
    }

    /**
     * Build top performing excavators based on AVG BCM/H.
     *
     * @param array $filters
     * @return array
     */
    protected function getTopPerformingExcavators(array $filters): array
    {
        $siteId = $filters['mine_site_id'] ?? null;
        $blockId = $filters['block_id'] ?? null;
        $shiftId = $filters['shift_id'] ?? null;
        $from = $filters['from'];
        $to = $filters['to'];

        $excavatorsData = DispatchTrip::when($siteId, function ($q) use ($siteId) {
                return $q->where('dispatch_trips.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('dispatch_trips.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('dispatch_trips.shift_id', $shiftId);
            })
            ->whereBetween('dispatch_trips.date', [$from, $to])
            ->whereNotNull('dispatch_trips.excavator_equipment_id')
            ->join('equipment_names as excavators', 'excavators.id', '=', 'dispatch_trips.excavator_equipment_id')
            ->leftJoin('shifts', 'shifts.id', '=', 'dispatch_trips.shift_id')
            ->groupBy(
                'dispatch_trips.excavator_equipment_id',
                'excavators.equipment_name',
                'dispatch_trips.shift_plan_id',
                'dispatch_trips.shift_id',
                'shifts.minimum_working_hours'
            )
            ->selectRaw('
                dispatch_trips.excavator_equipment_id,
                excavators.equipment_name as asset_id,
                dispatch_trips.shift_plan_id,
                dispatch_trips.shift_id,
                COALESCE(shifts.minimum_working_hours, 8) as shift_hours,
                SUM(dispatch_trips.quantity_bcm) as total_bcm,
                COUNT(dispatch_trips.id) as total_trips
            ')
            ->get();

        if ($excavatorsData->isEmpty()) {
            return [];
        }

        $excavatorAggregates = [];
        foreach ($excavatorsData as $row) {
            $excavatorId = $row->excavator_equipment_id;
            $operatorName = '---';

            $aggKey = $excavatorId;

            if (!isset($excavatorAggregates[$aggKey])) {
                $excavatorAggregates[$aggKey] = [
                    'asset_id' => $row->asset_id,
                    'op_name' => $operatorName,
                    'total_bcm' => 0.00,
                    'total_hours' => 0.00,
                ];
            }

            $excavatorAggregates[$aggKey]['total_bcm'] += (float) $row->total_bcm;
            $excavatorAggregates[$aggKey]['total_hours'] += (float) $row->shift_hours;
        }

        $resultExcavators = [];
        foreach ($excavatorAggregates as $agg) {
            $avgBcmH = $agg['total_hours'] > 0 ? round($agg['total_bcm'] / $agg['total_hours'], 2) : 0.00;
            $resultExcavators[] = [
                'asset_id' => $agg['asset_id'],
                'op_name' => $agg['op_name'],
                'avg_bcm_h' => $avgBcmH,
            ];
        }

        // Sort by avg_bcm_h DESC
        usort($resultExcavators, function ($a, $b) {
            return $b['avg_bcm_h'] <=> $a['avg_bcm_h'];
        });

        return array_slice($resultExcavators, 0, 5);
    }

    /**
     * Build top performing dumpers based on TRIPS/SHIFT.
     *
     * @param array $filters
     * @return array
     */
    protected function getTopPerformingDumpers(array $filters): array
    {
        $siteId = $filters['mine_site_id'] ?? null;
        $blockId = $filters['block_id'] ?? null;
        $shiftId = $filters['shift_id'] ?? null;
        $from = $filters['from'];
        $to = $filters['to'];

        $dumpersData = DispatchTrip::when($siteId, function ($q) use ($siteId) {
                return $q->where('dispatch_trips.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('dispatch_trips.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('dispatch_trips.shift_id', $shiftId);
            })
            ->whereBetween('dispatch_trips.date', [$from, $to])
            ->whereNotNull('dispatch_trips.dumper_equipment_id')
            ->join('equipment_names as dumpers', 'dumpers.id', '=', 'dispatch_trips.dumper_equipment_id')
            ->groupBy(
                'dispatch_trips.dumper_equipment_id',
                'dumpers.equipment_name',
                'dispatch_trips.shift_plan_id'
            )
            ->selectRaw('
                dispatch_trips.dumper_equipment_id,
                dumpers.equipment_name as asset_id,
                dispatch_trips.shift_plan_id,
                SUM(COALESCE(dispatch_trips.total_cycles, 1)) as total_trips
            ')
            ->get();

        if ($dumpersData->isEmpty()) {
            return [];
        }

        $dumperAggregates = [];
        foreach ($dumpersData as $row) {
            $dumperId = $row->dumper_equipment_id;
            $operatorName = '---';

            $aggKey = $dumperId;

            if (!isset($dumperAggregates[$aggKey])) {
                $dumperAggregates[$aggKey] = [
                    'asset_id' => $row->asset_id,
                    'op_name' => $operatorName,
                    'total_trips' => 0,
                    'shift_plans' => [],
                ];
            }

            $dumperAggregates[$aggKey]['total_trips'] += (int) $row->total_trips;
            $dumperAggregates[$aggKey]['shift_plans'][] = $row->shift_plan_id;
        }

        $resultDumpers = [];
        foreach ($dumperAggregates as $agg) {
            $uniqueShiftsCount = count(array_unique($agg['shift_plans']));
            $tripsPerShift = $uniqueShiftsCount > 0 ? round($agg['total_trips'] / $uniqueShiftsCount, 2) : 0.00;

            $resultDumpers[] = [
                'asset_id' => $agg['asset_id'],
                'op_name' => $agg['op_name'],
                'trips_shift' => $tripsPerShift,
            ];
        }

        // Sort by trips_shift DESC
        usort($resultDumpers, function ($a, $b) {
            return $b['trips_shift'] <=> $a['trips_shift'];
        });

        return array_slice($resultDumpers, 0, 5);
    }

    /**
     * Build shift delay analysis data.
     *
     * @param array $filters
     * @return array
     */
    protected function getShiftDelayAnalysis(array $filters): array
    {
        $siteId = $filters['mine_site_id'] ?? null;
        $blockId = $filters['block_id'] ?? null;
        $shiftId = $filters['shift_id'] ?? null;
        $from = $filters['from'];
        $to = $filters['to'];

        // Current period delays
        $delaysData = Delay::when($siteId, function ($q) use ($siteId) {
                return $q->where('delays.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('delays.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('delays.shift_id', $shiftId);
            })
            ->whereBetween('delays.date', [$from, $to])
            ->join('delay_categories', 'delay_categories.id', '=', 'delays.delay_category_id')
            ->groupBy('delay_categories.delay_category')
            ->selectRaw('
                delay_categories.delay_category as category,
                COALESCE(SUM(delays.duration_minutes), 0) as total_minutes
            ')
            ->get();

        $totalDelayMinutes = (int) $delaysData->sum('total_minutes');

        $categories = $delaysData->map(function ($row) {
            return [
                'category' => $row->category,
                'duration_minutes' => (int) $row->total_minutes,
            ];
        })->toArray();

        // Calculate comparison text: compare current period total delay minutes vs previous period total delay minutes
        $fromCarbon = Carbon::parse($from);
        $toCarbon = Carbon::parse($to);
        $daysDiff = $fromCarbon->diffInDays($toCarbon) + 1;

        $prevFrom = $fromCarbon->copy()->subDays($daysDiff)->toDateString();
        $prevTo = $fromCarbon->copy()->subDay()->toDateString();

        $prevTotalMins = Delay::when($siteId, function ($q) use ($siteId) {
                return $q->where('delays.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('delays.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('delays.shift_id', $shiftId);
            })
            ->whereBetween('delays.date', [$prevFrom, $prevTo])
            ->sum('duration_minutes') ?: 0;

        if ($prevTotalMins > 0) {
            $diffPercent = round((($totalDelayMinutes - $prevTotalMins) / $prevTotalMins) * 100);
            if ($diffPercent >= 0) {
                $comparisonText = "Total downtime is {$diffPercent}% higher than previous period.";
            } else {
                $absPercent = abs($diffPercent);
                $comparisonText = "Total downtime is {$absPercent}% lower than previous period.";
            }
        } else {
            $comparisonText = "Total downtime is 0% higher than previous period.";
        }

        return [
            'total_delay_minutes' => $totalDelayMinutes,
            'categories' => $categories,
            'comparison_text' => $comparisonText,
        ];
    }
}
