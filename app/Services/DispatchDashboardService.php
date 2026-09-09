<?php

namespace App\Services;

use App\Models\DispatchTrip;
use Carbon\Carbon;

class DispatchDashboardService
{
    /**
     * Build dispatch dashboard summary data.
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

        // 1. Single aggregate query for overall dispatch KPIs
        $kpis = DispatchTrip::when($siteId, function ($q) use ($siteId) {
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
                COALESCE(AVG(cycle_time_minutes), 0) as avg_cycle
            ')
            ->first();

        $totalTrips = (int) $kpis->total_trips;
        $totalBcm = round((float) $kpis->total_bcm, 2);
        $avgPayload = $totalTrips > 0 ? round($totalBcm / $totalTrips, 2) : 0.00;
        $avgCycleTime = round((float) $kpis->avg_cycle, 2);

        // 2. Trend Queries grouped by Date
        $trendRaw = DispatchTrip::when($siteId, function ($q) use ($siteId) {
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
            ->selectRaw('date, COALESCE(SUM(quantity_bcm), 0) as bcm')
            ->get()
            ->mapWithKeys(function ($item) {
                $dateStr = $item->date ? Carbon::parse($item->date)->toDateString() : '';
                return [$dateStr => $item->bcm];
            });

        $points = [];
        $startDate = Carbon::parse($from);
        $endDate = Carbon::parse($to);
        $curr = $startDate->copy();

        while ($curr->lte($endDate)) {
            $dateStr = $curr->toDateString();
            $dayLabel = $curr->format('D') . ' (' . $curr->format('d-M') . ')';

            $points[] = [
                'label' => $dayLabel,
                'value' => round((float) ($trendRaw[$dateStr] ?? 0.00), 2)
            ];

            $curr->addDay();
        }

        // 3. Cycle Time Distribution (conditional aggregation in a single query)
        $dist = DispatchTrip::when($siteId, function ($q) use ($siteId) {
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
                COALESCE(SUM(CASE WHEN cycle_time_minutes < 15 THEN 1 ELSE 0 END), 0) as interval_1,
                COALESCE(SUM(CASE WHEN cycle_time_minutes >= 15 AND cycle_time_minutes < 25 THEN 1 ELSE 0 END), 0) as interval_2,
                COALESCE(SUM(CASE WHEN cycle_time_minutes >= 25 AND cycle_time_minutes < 35 THEN 1 ELSE 0 END), 0) as interval_3,
                COALESCE(SUM(CASE WHEN cycle_time_minutes >= 35 THEN 1 ELSE 0 END), 0) as interval_4
            ')
            ->first();

        $intervals = ["<15m", "15-25m", "25-35m", ">35m"];
        $intervalValues = [
            (int) $dist->interval_1,
            (int) $dist->interval_2,
            (int) $dist->interval_3,
            (int) $dist->interval_4
        ];

        // 4. Productivity Splits
        $prod = DispatchTrip::when($siteId, function ($q) use ($siteId) {
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
                COALESCE(SUM(CASE WHEN dumper_equipment_id IS NOT NULL THEN quantity_bcm ELSE 0 END), 0) as dumpers_bcm,
                COALESCE(SUM(CASE WHEN excavator_equipment_id IS NOT NULL THEN quantity_bcm ELSE 0 END), 0) as excavators_bcm
            ')
            ->first();

        return [
            'kpis' => [
                'total_trips'          => ['value' => $totalTrips],
                'total_bcm_moved'      => ['value' => $totalBcm, 'unit' => 'BCM'],
                'avg_payload_per_trip' => ['value' => $avgPayload, 'unit' => 'BCM'],
                'avg_cycle_time'       => ['value' => $avgCycleTime, 'unit' => 'Minutes']
            ],
            'charts' => [
                'production_trend' => [
                    'granularity' => 'day',
                    'points'      => $points
                ],
                'cycle_time_distribution' => [
                    'intervals' => $intervals,
                    'values'    => $intervalValues
                ],
                'dumper_vs_excavator_productivity' => [
                    'dumpers_bcm'     => round((float) $prod->dumpers_bcm, 2),
                    'excavators_bcm'  => round((float) $prod->excavators_bcm, 2)
                ]
            ]
        ];
    }

    /**
     * Get Recent Trips.
     */
    public function getRecentTrips(array $filters, int $perPage = 10)
    {
        $siteId = $filters['mine_site_id'];
        $blockId = $filters['block_id'];
        $shiftId = $filters['shift_id'];
        $from = $filters['from'];
        $to = $filters['to'];
        $machineTypeId = $filters['machine_type_id'] ?? null;
        $machineNumberId = $filters['machine_number_id'] ?? null;

        $query = DispatchTrip::when($siteId, function ($q) use ($siteId) {
                return $q->where('dispatch_trips.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('dispatch_trips.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('dispatch_trips.shift_id', $shiftId);
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
                    $sub->where('dispatch_trips.dumper_equipment_id', $machineNumberId)
                        ->orWhere('dispatch_trips.excavator_equipment_id', $machineNumberId);
                });
            })
            ->whereBetween('dispatch_trips.date', [$from, $to])
            ->leftJoin('equipment_names as dumpers', 'dumpers.id', '=', 'dispatch_trips.dumper_equipment_id')
            ->leftJoin('equipment_names as excavators', 'excavators.id', '=', 'dispatch_trips.excavator_equipment_id')
            ->selectRaw('
                dispatch_trips.id,
                dispatch_trips.trip_reference_no,
                dispatch_trips.trip_date_time as time,
                dumpers.equipment_name as dumper,
                excavators.equipment_name as excavator,
                dispatch_trips.quantity_bcm as quantity,
                dispatch_trips.cycle_time_minutes as cycle_time
            ')
            ->orderBy('dispatch_trips.trip_date_time', 'DESC');

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(function ($item) {
            return [
                'id'                => $item->id,
                'trip_reference_no' => $item->trip_reference_no,
                'time'              => $item->time ? Carbon::parse($item->time)->toIso8601String() : null,
                'dumper'            => $item->dumper ?: 'N/A',
                'excavator'         => $item->excavator ?: 'N/A',
                'material'          => 'OB',
                'quantity'          => (float) $item->quantity,
                'cycle_time'        => (float) $item->cycle_time,
            ];
        });

        return [
            'items'        => $items,
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage()
        ];
    }
}
