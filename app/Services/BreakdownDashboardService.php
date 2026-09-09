<?php

namespace App\Services;

use App\Models\BreakdownTicket;
use Carbon\Carbon;

class BreakdownDashboardService
{
    /**
     * Build breakdown dashboard summary data.
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

        // 1. Single aggregate query for overall breakdown KPIs
        $kpis = BreakdownTicket::when($siteId, function ($q) use ($siteId) {
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
                COUNT(*) as total_events,
                COALESCE(SUM(downtime_minutes), 0) as total_downtime_mins,
                COUNT(DISTINCT equipment_name_id) as affected_machines
            ')
            ->first();

        $totalEvents = (int) $kpis->total_events;
        $totalDowntimeHours = round((float) $kpis->total_downtime_mins / 60, 2);
        $avgDowntime = $totalEvents > 0 ? round($totalDowntimeHours / $totalEvents, 2) : 0.00;
        $affectedMachines = (int) $kpis->affected_machines;

        // 2. Trend Queries grouped by Date
        $trendRaw = BreakdownTicket::when($siteId, function ($q) use ($siteId) {
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
            ->groupBy('date')
            ->selectRaw('
                date,
                COUNT(*) as count,
                COALESCE(SUM(downtime_minutes), 0) as mins
            ')
            ->get()
            ->keyBy(function ($item) {
                return $item->date ? Carbon::parse($item->date)->toDateString() : '';
            });

        $breakdownPoints = [];
        $downtimePoints = [];
        $startDate = Carbon::parse($from);
        $endDate = Carbon::parse($to);
        $curr = $startDate->copy();

        while ($curr->lte($endDate)) {
            $dateStr = $curr->toDateString();
            $dayLabel = $curr->format('D') . ' (' . $curr->format('d-M') . ')';

            $dayData = $trendRaw->get($dateStr);
            $breakdownPoints[] = [
                'label' => $dayLabel,
                'value' => $dayData ? (int) $dayData->count : 0
            ];
            $downtimePoints[] = [
                'label' => $dayLabel,
                'value' => $dayData ? round((float) $dayData->mins / 60, 2) : 0.00
            ];

            $curr->addDay();
        }

        // 3. Category Analysis
        $categoryData = BreakdownTicket::when($siteId, function ($q) use ($siteId) {
                return $q->where('breakdown_tickets.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('breakdown_tickets.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('breakdown_tickets.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('breakdown_tickets.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('breakdown_tickets.equipment_name_id', $machineNumberId);
            })
            ->whereBetween('breakdown_tickets.date', [$from, $to])
            ->join('breakdown_types', 'breakdown_types.id', '=', 'breakdown_tickets.breakdown_type_id')
            ->groupBy('breakdown_types.breakdown_type')
            ->selectRaw('breakdown_types.breakdown_type, COUNT(*) as count')
            ->get();

        $categories = [];
        $categoryValues = [];
        foreach ($categoryData as $row) {
            $categories[] = $row->breakdown_type;
            $categoryValues[] = (int) $row->count;
        }

        // 4. Machine Ranking (Top 10 by downtime)
        $machineRanking = BreakdownTicket::when($siteId, function ($q) use ($siteId) {
                return $q->where('breakdown_tickets.mine_site_id', $siteId);
            })
            ->when($blockId, function ($q) use ($blockId) {
                return $q->where('breakdown_tickets.block_id', $blockId);
            })
            ->when($shiftId, function ($q) use ($shiftId) {
                return $q->where('breakdown_tickets.shift_id', $shiftId);
            })
            ->when($machineTypeId, function ($q) use ($machineTypeId) {
                return $q->where('breakdown_tickets.equipment_id', $machineTypeId);
            })
            ->when($machineNumberId, function ($q) use ($machineNumberId) {
                return $q->where('breakdown_tickets.equipment_name_id', $machineNumberId);
            })
            ->whereBetween('breakdown_tickets.date', [$from, $to])
            ->join('equipment_names', 'equipment_names.id', '=', 'breakdown_tickets.equipment_name_id')
            ->groupBy('equipment_names.equipment_name')
            ->selectRaw('equipment_names.equipment_name, COALESCE(SUM(breakdown_tickets.downtime_minutes), 0) as total_mins')
            ->orderBy('total_mins', 'DESC')
            ->limit(10)
            ->get();

        $rankingMachines = [];
        $rankingValues = [];
        foreach ($machineRanking as $row) {
            $rankingMachines[] = $row->equipment_name;
            $rankingValues[] = round((float) $row->total_mins / 60, 2);
        }

        return [
            'kpis' => [
                'total_breakdown_events'     => ['value' => $totalEvents],
                'total_downtime_hours'       => ['value' => $totalDowntimeHours],
                'avg_downtime_per_breakdown' => ['value' => $avgDowntime, 'unit' => 'Hours'],
                'affected_machines'          => ['value' => $affectedMachines]
            ],
            'charts' => [
                'breakdown_trend' => [
                    'granularity' => 'day',
                    'points'      => $breakdownPoints
                ],
                'downtime_trend' => [
                    'granularity' => 'day',
                    'points'      => $downtimePoints
                ],
                'category_analysis' => [
                    'categories' => $categories,
                    'values'     => $categoryValues
                ],
                'machine_ranking' => [
                    'machines' => $rankingMachines,
                    'values'   => $rankingValues
                ]
            ]
        ];
    }
}
