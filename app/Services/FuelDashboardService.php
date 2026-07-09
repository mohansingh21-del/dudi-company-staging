<?php

namespace App\Services;

use App\Models\FuelEntry;
use App\Models\DispatchTrip;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FuelDashboardService
{
    /**
     * Build fuel dashboard summary data.
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

        // 1. Single aggregate query for overall fuel KPIs
        $kpis = FuelEntry::when($siteId, function ($q) use ($siteId) {
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
            ->where('status', 'active')
            ->selectRaw('
                COALESCE(SUM(fuel_issued), 0) as total_issued,
                COALESCE(SUM(fuel_consumption), 0) as total_consumed,
                COALESCE(SUM(work_done_bcm), 0) as total_work_bcm,
                COUNT(DISTINCT equipment_name_id) as machines_count
            ')
            ->first();

        $totalIssued = (float) $kpis->total_issued;
        $totalConsumed = (float) $kpis->total_consumed;
        $workBcm = (float) $kpis->total_work_bcm;
        $machinesRefueled = (int) $kpis->machines_count;

        // Fallback to dispatch BCM if work_done_bcm is 0
        if ($workBcm <= 0) {
            $dispatchBcm = DispatchTrip::when($siteId, function ($q) use ($siteId) {
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
                ->sum('quantity_bcm');
            $workBcm = (float) $dispatchBcm;
        }

        $fuelEfficiency = $workBcm > 0 ? round($totalConsumed / $workBcm, 2) : 0.00;

        // 2. Consumption Trend
        $trendRaw = FuelEntry::when($siteId, function ($q) use ($siteId) {
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
            ->where('status', 'active')
            ->groupBy('date')
            ->orderBy('date')
            ->selectRaw('date, COALESCE(SUM(fuel_consumption), 0) as val')
            ->get()
            ->mapWithKeys(function ($item) {
                $dateStr = $item->date ? Carbon::parse($item->date)->toDateString() : '';
                return [$dateStr => $item->val];
            });

        $points = [];
        $startDate = Carbon::parse($from);
        $endDate = Carbon::parse($to);
        $curr = $startDate->copy();

        while ($curr->lte($endDate)) {
            $dateStr = $curr->toDateString();
            $dayLabel = $curr->format('D'); // Mon, Tue, etc.
            $points[] = [
                'label' => $dayLabel . ' (' . $curr->format('d-M') . ')',
                'value' => round((float) ($trendRaw[$dateStr] ?? 0.00), 2)
            ];
            $curr->addDay();
        }

        // 3. By Machine Type
        $byTypeRaw = FuelEntry::when($siteId, function ($q) use ($siteId) {
                return $q->where('fuel_entries.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('fuel_entries.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('fuel_entries.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('fuel_entries.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('fuel_entries.equipment_name_id', $machineNumberId);
            })
            ->whereBetween('fuel_entries.date', [$from, $to])
            ->where('fuel_entries.status', 'active')
            ->join('equipments', 'equipments.id', '=', 'fuel_entries.equipment_id')
            ->groupBy('equipments.name')
            ->selectRaw('equipments.name, COALESCE(SUM(fuel_entries.fuel_consumption), 0) as val')
            ->get();

        $categories = [];
        $byTypeValues = [];
        foreach ($byTypeRaw as $row) {
            $categories[] = $row->name;
            $byTypeValues[] = round((float) $row->val, 2);
        }

        // 4. Efficiency By Machine
        $byMachineRaw = FuelEntry::when($siteId, function ($q) use ($siteId) {
                return $q->where('fuel_entries.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('fuel_entries.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('fuel_entries.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('fuel_entries.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('fuel_entries.equipment_name_id', $machineNumberId);
            })
            ->whereBetween('fuel_entries.date', [$from, $to])
            ->where('fuel_entries.status', 'active')
            ->join('equipment_names', 'equipment_names.id', '=', 'fuel_entries.equipment_name_id')
            ->groupBy('equipment_names.equipment_name')
            ->selectRaw('
                equipment_names.equipment_name,
                COALESCE(SUM(fuel_entries.fuel_consumption), 0) as cons,
                COALESCE(SUM(fuel_entries.work_done_bcm), 0) as bcm
            ')
            ->orderBy('cons', 'DESC')
            ->limit(10)
            ->get();

        $machines = [];
        $effValues = [];
        foreach ($byMachineRaw as $row) {
            $machines[] = $row->equipment_name;
            $bcmVal = (float) $row->bcm;
            $effValues[] = $bcmVal > 0 ? round((float) $row->cons / $bcmVal, 2) : 0.00;
        }

        return [
            'kpis' => [
                'total_fuel_issued'   => ['value' => $totalIssued, 'unit' => 'Liters'],
                'total_fuel_consumed' => ['value' => $totalConsumed, 'unit' => 'Liters'],
                'fuel_efficiency'     => ['value' => $fuelEfficiency, 'unit' => 'L/BCM'],
                'machines_refueled'   => ['value' => $machinesRefueled]
            ],
            'charts' => [
                'consumption_trend' => [
                    'granularity' => 'day',
                    'points'      => $points
                ],
                'by_machine_type' => [
                    'categories' => $categories,
                    'values'     => $byTypeValues
                ],
                'efficiency_by_machine' => [
                    'machines' => $machines,
                    'values'   => $effValues
                ]
            ]
        ];
    }

    /**
     * Get Top Consumers table data.
     */
    public function getTopConsumers(array $filters, int $perPage = 10)
    {
        $siteId = $filters['mine_site_id'];
        $blockId = $filters['block_id'];
        $shiftId = $filters['shift_id'];
        $from = $filters['from'];
        $to = $filters['to'];
        $machineTypeId = $filters['machine_type_id'] ?? null;
        $machineNumberId = $filters['machine_number_id'] ?? null;

        $query = FuelEntry::when($siteId, function ($q) use ($siteId) {
                return $q->where('fuel_entries.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('fuel_entries.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('fuel_entries.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('fuel_entries.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('fuel_entries.equipment_name_id', $machineNumberId);
            })
            ->whereBetween('fuel_entries.date', [$from, $to])
            ->where('fuel_entries.status', 'active')
            ->join('equipment_names', 'equipment_names.id', '=', 'fuel_entries.equipment_name_id')
            ->groupBy('equipment_names.id', 'equipment_names.equipment_name')
            ->selectRaw('
                equipment_names.id as equipment_name_id,
                equipment_names.equipment_name as asset_id,
                COALESCE(SUM(fuel_entries.fuel_consumption), 0) as fuel_consumed,
                COALESCE(MAX(fuel_entries.hours_meter_reading) - MIN(fuel_entries.hours_meter_reading), 0) as hours_run,
                COALESCE(SUM(fuel_entries.work_done_bcm), 0) as total_bcm
            ')
            ->orderBy('fuel_consumed', 'DESC');

        $paginator = $query->paginate($perPage);

        $currentPage = $paginator->currentPage();
        $items = collect($paginator->items())->map(function ($item, $index) use ($currentPage, $perPage) {
            $fuelConsumed = (float) $item->fuel_consumed;
            $totalBcm = (float) $item->total_bcm;
            $eff = $totalBcm > 0 ? round($fuelConsumed / $totalBcm, 2) : 0.00;

            return [
                'rank'             => ($currentPage - 1) * $perPage + $index + 1,
                'asset_id'         => $item->asset_id,
                'fuel_consumed'    => $fuelConsumed,
                'hours_run'        => round((float) $item->hours_run, 2),
                'efficiency_ratio' => $eff,
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

    /**
     * Get Low Efficiency Alerts table data.
     */
    public function getLowEfficiency(array $filters, int $perPage = 10)
    {
        $siteId = $filters['mine_site_id'];
        $blockId = $filters['block_id'];
        $shiftId = $filters['shift_id'];
        $from = $filters['from'];
        $to = $filters['to'];
        $machineTypeId = $filters['machine_type_id'] ?? null;
        $machineNumberId = $filters['machine_number_id'] ?? null;

        // Calculate site-wide baseline average efficiency (L/BCM)
        $avgSiteEff = FuelEntry::when($siteId, function ($q) use ($siteId) {
                return $q->where('mine_site_id', $siteId);
            })
            ->whereBetween('date', [$from, $to])
            ->where('status', 'active')
            ->selectRaw('COALESCE(SUM(fuel_consumption) / NULLIF(SUM(work_done_bcm), 0), 0) as avg_eff')
            ->value('avg_eff') ?: 1.0;

        $query = FuelEntry::when($siteId, function ($q) use ($siteId) {
                return $q->where('fuel_entries.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('fuel_entries.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('fuel_entries.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('fuel_entries.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('fuel_entries.equipment_name_id', $machineNumberId);
            })
            ->whereBetween('fuel_entries.date', [$from, $to])
            ->where('fuel_entries.status', 'active')
            ->join('equipment_names', 'equipment_names.id', '=', 'fuel_entries.equipment_name_id')
            ->groupBy('equipment_names.id', 'equipment_names.equipment_name')
            ->selectRaw('
                equipment_names.equipment_name as asset_id,
                COALESCE(SUM(fuel_entries.fuel_consumption), 0) as fuel_consumed,
                COALESCE(SUM(fuel_entries.work_done_bcm), 0) as total_bcm,
                COALESCE(SUM(fuel_entries.fuel_consumption) / NULLIF(SUM(fuel_entries.work_done_bcm), 0), 0) as efficiency
            ')
            ->having('efficiency', '>', $avgSiteEff * 1.15) // 15% worse than average
            ->orderBy('efficiency', 'DESC');

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(function ($item) use ($avgSiteEff) {
            $eff = (float) $item->efficiency;
            $variance = $avgSiteEff > 0 ? round((($eff - $avgSiteEff) / $avgSiteEff) * 100, 2) : 0.00;

            return [
                'asset_id'           => $item->asset_id,
                'efficiency'         => $eff,
                'baseline_efficiency'=> round($avgSiteEff, 2),
                'variance_percent'   => $variance,
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

    /**
     * Get Recent Fuel Transactions.
     */
    public function getRecentTransactions(array $filters, int $perPage = 10)
    {
        $siteId = $filters['mine_site_id'];
        $blockId = $filters['block_id'];
        $shiftId = $filters['shift_id'];
        $from = $filters['from'];
        $to = $filters['to'];
        $machineTypeId = $filters['machine_type_id'] ?? null;
        $machineNumberId = $filters['machine_number_id'] ?? null;

        $query = FuelEntry::when($siteId, function ($q) use ($siteId) {
                return $q->where('fuel_entries.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('fuel_entries.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('fuel_entries.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('fuel_entries.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('fuel_entries.equipment_name_id', $machineNumberId);
            })
            ->whereBetween('fuel_entries.date', [$from, $to])
            ->where('fuel_entries.status', 'active')
            ->join('equipment_names', 'equipment_names.id', '=', 'fuel_entries.equipment_name_id')
            ->leftJoin('employees', 'employees.id', '=', 'fuel_entries.operator_id')
            ->selectRaw('
                fuel_entries.fuel_log_date as time,
                equipment_names.equipment_name as vehicle,
                fuel_entries.fuel_issued as quantity,
                employees.name as driver,
                fuel_entries.fuel_source as source
            ')
            ->orderBy('fuel_entries.fuel_log_date', 'DESC');

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(function ($item) {
            return [
                'time'     => $item->time ? Carbon::parse($item->time)->toIso8601String() : null,
                'vehicle'  => $item->vehicle,
                'quantity' => (float) $item->quantity,
                'driver'   => $item->driver ?: 'N/A',
                'source'   => $item->source ?: 'N/A',
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
