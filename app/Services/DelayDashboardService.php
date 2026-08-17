<?php

namespace App\Services;

use App\Models\Delay;
use Carbon\Carbon;

class DelayDashboardService
{
    /**
     * Build delay dashboard summary data.
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
        $delayCategoryId = $filters['delay_category_id'] ?? null;
        $delaySeverity = $filters['delay_severity'] ?? null;

        // 1. Single aggregate query for overall delay KPIs
        $kpis = Delay::when($siteId, function ($q) use ($siteId) {
                return $q->where('mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('delays.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('delays.equipment_name_id', $machineNumberId);
            })
            ->when($delayCategoryId, function ($q) use ($delayCategoryId) {
                return $q->where('delays.delay_category_id', $delayCategoryId);
            })
            ->when($delaySeverity, function ($q) use ($delaySeverity) {
                return $q->where('delays.severity', $delaySeverity);
            })
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COUNT(*) as total_events,
                COALESCE(SUM(duration_minutes), 0) as total_mins,
                COALESCE(SUM(estimated_production_loss_bcm), 0) as total_loss
            ')
            ->first();

        $totalEvents = (int) $kpis->total_events;
        $totalDelayHours = round((float) $kpis->total_mins / 60, 2);
        $avgDelayDuration = $totalEvents > 0 ? round((float) $kpis->total_mins / $totalEvents, 2) : 0.00;
        $productionLoss = round((float) $kpis->total_loss, 2);

        // 2. Trend Queries grouped by Date
        $trendRaw = Delay::when($siteId, function ($q) use ($siteId) {
                return $q->where('mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('delays.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('delays.equipment_name_id', $machineNumberId);
            })
            ->when($delayCategoryId, function ($q) use ($delayCategoryId) {
                return $q->where('delays.delay_category_id', $delayCategoryId);
            })
            ->when($delaySeverity, function ($q) use ($delaySeverity) {
                return $q->where('delays.severity', $delaySeverity);
            })
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')
            ->selectRaw('
                date,
                COUNT(*) as count,
                COALESCE(SUM(duration_minutes), 0) as mins
            ')
            ->get()
            ->keyBy(function ($item) {
                return $item->date ? Carbon::parse($item->date)->toDateString() : '';
            });

        $delayPoints = [];
        $startDate = Carbon::parse($from);
        $endDate = Carbon::parse($to);
        $curr = $startDate->copy();

        while ($curr->lte($endDate)) {
            $dateStr = $curr->toDateString();
            $dayLabel = $curr->format('D') . ' (' . $curr->format('d-M') . ')';

            $dayData = $trendRaw->get($dateStr);
            $delayPoints[] = [
                'label' => $dayLabel,
                'value' => $dayData ? round((float) $dayData->mins / 60, 2) : 0.00
            ];

            $curr->addDay();
        }

        // 3. Shift Delays vs Operational Delays (Clean taxonomy split)
        $alignment = Delay::when($siteId, function ($q) use ($siteId) {
                return $q->where('mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('delays.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('delays.equipment_name_id', $machineNumberId);
            })
            ->when($delayCategoryId, function ($q) use ($delayCategoryId) {
                return $q->where('delays.delay_category_id', $delayCategoryId);
            })
            ->when($delaySeverity, function ($q) use ($delaySeverity) {
                return $q->where('delays.severity', $delaySeverity);
            })
            ->whereBetween('date', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN equipment_name_id IS NULL THEN duration_minutes ELSE 0 END), 0) as shift_delay_mins,
                COALESCE(SUM(CASE WHEN equipment_name_id IS NOT NULL THEN duration_minutes ELSE 0 END), 0) as operational_delay_mins
            ')
            ->first();

        $shiftDelaysHours = round((float) $alignment->shift_delay_mins / 60, 2);
        $operationalDelaysHours = round((float) $alignment->operational_delay_mins / 60, 2);

        // 4. By Category
        $byCategoryData = Delay::when($siteId, function ($q) use ($siteId) {
                return $q->where('delays.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('delays.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('delays.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('delays.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('delays.equipment_name_id', $machineNumberId);
            })
            ->when($delayCategoryId, function ($q) use ($delayCategoryId) {
                return $q->where('delays.delay_category_id', $delayCategoryId);
            })
            ->when($delaySeverity, function ($q) use ($delaySeverity) {
                return $q->where('delays.severity', $delaySeverity);
            })
            ->whereBetween('delays.date', [$from, $to])
            ->join('delay_categories', 'delay_categories.id', '=', 'delays.delay_category_id')
            ->groupBy('delay_categories.delay_category')
            ->selectRaw('delay_categories.delay_category, COALESCE(SUM(delays.duration_minutes), 0) as val')
            ->get();

        $categories = [];
        $categoryValues = [];
        foreach ($byCategoryData as $row) {
            $categories[] = $row->delay_category;
            $categoryValues[] = round((float) $row->val / 60, 2); // Convert to hours for chart readability
        }

        return [
            'kpis' => [
                'total_delay_events'     => ['value' => $totalEvents],
                'total_delay_hours'       => ['value' => $totalDelayHours],
                'avg_delay_duration'     => ['value' => $avgDelayDuration, 'unit' => 'Minutes'],
                'production_loss_impact' => ['value' => $productionLoss, 'unit' => 'BCM']
            ],
            'charts' => [
                'delay_trend' => [
                    'granularity' => 'day',
                    'points'      => $delayPoints
                ],
                'shift_delays_vs_operational' => [
                    'shift_delays'       => $shiftDelaysHours,
                    'operational_delays' => $operationalDelaysHours
                ],
                'by_category' => [
                    'categories' => $categories,
                    'values'     => $categoryValues
                ]
            ]
        ];
    }

    /**
     * Get Top Delay Categories table data.
     */
    public function getTopCategories(array $filters, int $perPage = 10)
    {
        $siteId = $filters['mine_site_id'];
        $blockId = $filters['block_id'];
        $shiftId = $filters['shift_id'];
        $from = $filters['from'];
        $to = $filters['to'];
        $machineTypeId = $filters['machine_type_id'] ?? null;
        $machineNumberId = $filters['machine_number_id'] ?? null;
        $delayCategoryId = $filters['delay_category_id'] ?? null;
        $delaySeverity = $filters['delay_severity'] ?? null;

        // Get total delay minutes for percentage calculations
        $totalDelayMins = Delay::when($siteId, function ($q) use ($siteId) {
                return $q->where('mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('delays.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('delays.equipment_name_id', $machineNumberId);
            })
            ->when($delayCategoryId, function ($q) use ($delayCategoryId) {
                return $q->where('delays.delay_category_id', $delayCategoryId);
            })
            ->when($delaySeverity, function ($q) use ($delaySeverity) {
                return $q->where('delays.severity', $delaySeverity);
            })
            ->whereBetween('date', [$from, $to])
            ->sum('duration_minutes') ?: 1;

        $query = Delay::when($siteId, function ($q) use ($siteId) {
                return $q->where('delays.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('delays.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('delays.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('delays.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('delays.equipment_name_id', $machineNumberId);
            })
            ->when($delayCategoryId, function ($q) use ($delayCategoryId) {
                return $q->where('delays.delay_category_id', $delayCategoryId);
            })
            ->when($delaySeverity, function ($q) use ($delaySeverity) {
                return $q->where('delays.severity', $delaySeverity);
            })
            ->whereBetween('delays.date', [$from, $to])
            ->join('delay_categories', 'delay_categories.id', '=', 'delays.delay_category_id')
            ->groupBy('delay_categories.id', 'delay_categories.delay_category')
            ->selectRaw('
                delay_categories.delay_category as name,
                COALESCE(SUM(delays.duration_minutes), 0) as total_duration
            ')
            ->orderBy('total_duration', 'DESC');

        $paginator = $query->paginate($perPage);

        $currentPage = $paginator->currentPage();
        $items = collect($paginator->items())->map(function ($item, $index) use ($currentPage, $perPage, $totalDelayMins) {
            $duration = (float) $item->total_duration;
            $percentage = round(($duration / $totalDelayMins) * 100, 2);

            return [
                'rank'           => ($currentPage - 1) * $perPage + $index + 1,
                'category_name'  => $item->name,
                'total_duration' => $duration,
                'percentage'     => $percentage,
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
     * Get Critical Delay Events.
     */
    public function getCriticalDelays(array $filters, int $perPage = 10)
    {
        $siteId = $filters['mine_site_id'];
        $blockId = $filters['block_id'];
        $shiftId = $filters['shift_id'];
        $from = $filters['from'];
        $to = $filters['to'];
        $machineTypeId = $filters['machine_type_id'] ?? null;
        $machineNumberId = $filters['machine_number_id'] ?? null;
        $delayCategoryId = $filters['delay_category_id'] ?? null;
        $delaySeverity = $filters['delay_severity'] ?? null;

        $query = Delay::when($siteId, function ($q) use ($siteId) {
                return $q->where('delays.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('delays.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('delays.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('delays.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('delays.equipment_name_id', $machineNumberId);
            })
            ->when($delayCategoryId, function ($q) use ($delayCategoryId) {
                return $q->where('delays.delay_category_id', $delayCategoryId);
            })
            ->when($delaySeverity, function ($q) use ($delaySeverity) {
                return $q->where('delays.severity', $delaySeverity);
            })
            ->whereBetween('delays.date', [$from, $to])
            ->where(function ($q) {
                $q->whereNull('delays.end_time')
                  ->orWhere('delays.duration_minutes', '>=', 30);
            })
            ->join('delay_categories', 'delay_categories.id', '=', 'delays.delay_category_id')
            ->leftJoin('equipment_names', 'equipment_names.id', '=', 'delays.equipment_name_id')
            ->selectRaw('
                delays.id,
                delays.delay_ref_no,
                delay_categories.delay_category as category,
                equipment_names.equipment_name as asset_id,
                delays.start_time,
                delays.end_time,
                delays.duration_minutes
            ')
            ->orderBy('delays.duration_minutes', 'DESC');

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(function ($item) {
            return [
                'id'               => $item->id,
                'delay_ref_no'     => $item->delay_ref_no,
                'category'         => $item->category,
                'asset_id'         => $item->asset_id ?: 'N/A',
                'start_time'       => $item->start_time ? Carbon::parse($item->start_time)->toIso8601String() : null,
                'end_time'         => $item->end_time ? Carbon::parse($item->end_time)->toIso8601String() : null,
                'duration_minutes' => $item->end_time ? (int) $item->duration_minutes : null,
                'status'           => $item->end_time ? 'Resolved' : 'Active',
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
     * Get Recent Delay Logs.
     */
    public function getRecentDelays(array $filters, int $perPage = 10)
    {
        $siteId = $filters['mine_site_id'];
        $blockId = $filters['block_id'];
        $shiftId = $filters['shift_id'];
        $from = $filters['from'];
        $to = $filters['to'];
        $machineTypeId = $filters['machine_type_id'] ?? null;
        $machineNumberId = $filters['machine_number_id'] ?? null;
        $delayCategoryId = $filters['delay_category_id'] ?? null;
        $delaySeverity = $filters['delay_severity'] ?? null;

        $query = Delay::when($siteId, function ($q) use ($siteId) {
                return $q->where('delays.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('delays.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('delays.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('delays.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('delays.equipment_name_id', $machineNumberId);
            })
            ->when($delayCategoryId, function ($q) use ($delayCategoryId) {
                return $q->where('delays.delay_category_id', $delayCategoryId);
            })
            ->when($delaySeverity, function ($q) use ($delaySeverity) {
                return $q->where('delays.severity', $delaySeverity);
            })
            ->whereBetween('delays.date', [$from, $to])
            ->join('delay_categories', 'delay_categories.id', '=', 'delays.delay_category_id')
            ->leftJoin('equipment_names', 'equipment_names.id', '=', 'delays.equipment_name_id')
            ->selectRaw('
                delays.id,
                delays.delay_ref_no,
                delay_categories.delay_category as category,
                equipment_names.equipment_name as asset_id,
                delays.start_time,
                delays.end_time,
                delays.duration_minutes
            ')
            ->orderBy('delays.start_time', 'DESC');

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(function ($item) {
            return [
                'id'               => $item->id,
                'delay_ref_no'     => $item->delay_ref_no,
                'category'         => $item->category,
                'asset_id'         => $item->asset_id ?: 'N/A',
                'start_time'       => $item->start_time ? Carbon::parse($item->start_time)->toIso8601String() : null,
                'end_time'         => $item->end_time ? Carbon::parse($item->end_time)->toIso8601String() : null,
                'duration_minutes' => $item->end_time ? (int) $item->duration_minutes : null,
                'status'           => $item->end_time ? 'Resolved' : 'Active',
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
