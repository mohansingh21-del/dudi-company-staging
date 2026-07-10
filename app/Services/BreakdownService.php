<?php

namespace App\Services;

use App\Models\BreakdownTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;

class BreakdownService
{
    /**
     * List breakdown tickets with filters and compute dashboard stats.
     *
     * @param array $filters
     * @return array
     */
    public function list(array $filters)
    {
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 10;

        $query = BreakdownTicket::with(['equipment', 'equipmentName', 'shift', 'reporter', 'resolver.employee', 'equipmentAllocation', 'breakdownType']);

        // Filters
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['shift_id'])) {
            $query->where('shift_id', $filters['shift_id']);
        }

        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['equipment_id'])) {
            $query->where('equipment_id', $filters['equipment_id']);
        }

        if (isset($filters['equipment_name_id'])) {
            $query->where('equipment_name_id', $filters['equipment_name_id']);
        }

        if (isset($filters['equipment_allocation_id'])) {
            $query->where('equipment_allocation_id', $filters['equipment_allocation_id']);
        }

        if (isset($filters['breakdown_type_id'])) {
            $query->where('breakdown_type_id', $filters['breakdown_type_id']);
        }

        // Filter site_id via shift_plans of the same date
        if (isset($filters['site_id'])) {
            $query->whereExists(function ($q) use ($filters) {
                $q->select(DB::raw(1))
                    ->from('shift_plans')
                    ->whereColumn('shift_plans.shift_id', 'breakdown_tickets.shift_id')
                    ->whereColumn('shift_plans.planning_date', DB::raw('DATE(breakdown_tickets.breakdown_date_time)'))
                    ->where('shift_plans.site_id', $filters['site_id']);
            });
        }

        // Date range filters
        $dateFrom = null;
        $dateTo = null;
        if (isset($filters['date_from'])) {
            $dateFromVal = $filters['date_from'];
            if (strpos($dateFromVal, '/') !== false) {
                $dateFrom = \Carbon\Carbon::createFromFormat('d/m/Y', $dateFromVal)->startOfDay();
            } else {
                $dateFrom = \Carbon\Carbon::parse($dateFromVal)->startOfDay();
            }
            $query->where('breakdown_date_time', '>=', $dateFrom);
        }

        if (isset($filters['date_to'])) {
            $dateToVal = $filters['date_to'];
            if (strpos($dateToVal, '/') !== false) {
                $dateTo = \Carbon\Carbon::createFromFormat('d/m/Y', $dateToVal)->endOfDay();
            } else {
                $dateTo = \Carbon\Carbon::parse($dateToVal)->endOfDay();
            }
            $query->where('breakdown_date_time', '<=', $dateTo);
        }

        // Search against ticket_number / description / equipment_name
        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('equipmentName', function ($qName) use ($search) {
                        $qName->where('equipment_name', 'like', "%{$search}%");
                    });
            });
        }

        // Dashboard Base Query (copies filters)
        $dashboardQuery = clone $query;

        // Default sort
        $query->latest();

        // PAGINATION
        $tickets = $query->paginate($limit);

        // DASHBOARD COMPUTATIONS
        // 1. open_tickets = count where status in (open, in_progress, on_hold)
        $openTickets = (clone $dashboardQuery)
            ->whereIn('status', ['open', 'in_progress', 'on_hold'])
            ->count();

        // 2. closed_today = count where status = closed AND DATE(resolved_at) = today
        $closedToday = (clone $dashboardQuery)
            ->where('status', 'closed')
            ->whereNotNull('resolved_at')
            ->whereDate('resolved_at', \Carbon\Carbon::today()->toDateString())
            ->count();

        // 3. total_downtime_hours = SUM(downtime_minutes)/60 over the filtered period, rounded to 2 decimals
        $totalDowntimeMinutes = (clone $dashboardQuery)->sum('downtime_minutes');
        $totalDowntimeHours = round($totalDowntimeMinutes / 60, 2);

        // 4. mttr_hours = AVG(downtime_end - downtime_start) in hours, over closed tickets in period, rounded to 2 decimals
        $mttrResult = (clone $dashboardQuery)
            ->where('status', 'closed')
            ->whereNotNull('downtime_end')
            ->whereNotNull('downtime_start')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, downtime_start, downtime_end)) as avg_minutes')
            ->first();
        $mttrHours = $mttrResult && $mttrResult->avg_minutes !== null ? round($mttrResult->avg_minutes / 60, 2) : 0.00;

        // 5. equipment_availability_percent
        // period_hours = (date_to - date_from in hours) * count(distinct equipment_id)
        $hours = 0;
        if ($dateFrom && $dateTo) {
            $hours = $dateFrom->diffInHours($dateTo);
        } else {
            $minMax = (clone $dashboardQuery)
                ->selectRaw('MIN(downtime_start) as min_start, MAX(downtime_start) as max_start')
                ->first();
            if ($minMax && $minMax->min_start && $minMax->max_start) {
                $f = \Carbon\Carbon::parse($minMax->min_start)->startOfDay();
                $t = \Carbon\Carbon::parse($minMax->max_start)->endOfDay();
                $hours = $f->diffInHours($t);
            } else {
                $hours = 24;
            }
        }
        $hours = max($hours, 1);

        $equipmentCount = (clone $dashboardQuery)
            ->distinct()
            ->count('equipment_id');
        $equipmentCount = max($equipmentCount, 1);

        $periodHours = $hours * $equipmentCount;
        $equipmentAvailabilityPercent = round((($periodHours - $totalDowntimeHours) / $periodHours) * 100, 2);
        $equipmentAvailabilityPercent = max(0.00, min(100.00, $equipmentAvailabilityPercent));

        // NEW KPIs & CHARTS CALCULATIONS
        // 6. total_events = count of all breakdown tickets in the filtered period
        $totalEvents = (clone $dashboardQuery)->count();

        // 7. closed_tickets = count of closed breakdown tickets in the filtered period
        $closedTickets = (clone $dashboardQuery)
            ->where('status', 'closed')
            ->count();

        // Determine trend and scoring start/end dates
        $trendStart = $dateFrom ? $dateFrom->copy() : \Carbon\Carbon::now()->subDays(6)->startOfDay();
        $trendEnd = $dateTo ? $dateTo->copy() : \Carbon\Carbon::now()->endOfDay();

        $scoringHours = $trendStart->diffInHours($trendEnd);
        $scoringHours = max($scoringHours, 1);

        // Fetch machine stats for the filtered period
        $machineStats = (clone $dashboardQuery)
            ->select('equipment_name_id')
            ->selectRaw('SUM(downtime_minutes) as total_downtime_minutes')
            ->selectRaw('COUNT(*) as breakdown_count')
            ->groupBy('equipment_name_id')
            ->with('equipmentName')
            ->get();

        $affectedMachines = $machineStats->count();

        $machineStatsCalculated = [];
        foreach ($machineStats as $stat) {
            if (!$stat->equipmentName) {
                continue;
            }
            $mDowntimeHours = round($stat->total_downtime_minutes / 60, 2);
            $mBreakdownCount = $stat->breakdown_count;
            $mAvailability = (($scoringHours - $mDowntimeHours) / $scoringHours) * 100;
            $mScore = max(0.00, min(100.00, $mAvailability - ($mBreakdownCount * 8.5)));
            $mScore = round($mScore, 2);

            $machineStatsCalculated[] = [
                'equipment_name_id' => $stat->equipment_name_id,
                'machine' => $stat->equipmentName->equipment_name,
                'downtime_hours' => $mDowntimeHours,
                'breakdowns' => $mBreakdownCount,
                'reliability_score' => $mScore
            ];
        }

        // Rank Most/Least Reliable machines
        $mostReliableMachine = null;
        $leastReliableMachine = null;

        if (!empty($machineStatsCalculated)) {
            $mostReliableRaw = collect($machineStatsCalculated)->sort(function ($a, $b) {
                if ($a['reliability_score'] != $b['reliability_score']) {
                    return $b['reliability_score'] <=> $a['reliability_score']; // Descending
                }
                if ($a['downtime_hours'] != $b['downtime_hours']) {
                    return $a['downtime_hours'] <=> $b['downtime_hours']; // Ascending
                }
                return $a['breakdowns'] <=> $b['breakdowns']; // Ascending
            })->first();

            if ($mostReliableRaw) {
                $mostReliableMachine = [
                    'machine' => $mostReliableRaw['machine'],
                    'downtime' => $mostReliableRaw['downtime_hours'] . ' Hrs',
                    'downtime_hours' => $mostReliableRaw['downtime_hours'],
                    'breakdowns' => $mostReliableRaw['breakdowns'],
                    'reliability_score' => $mostReliableRaw['reliability_score']
                ];
            }

            $leastReliableRaw = collect($machineStatsCalculated)->sort(function ($a, $b) {
                if ($a['reliability_score'] != $b['reliability_score']) {
                    return $a['reliability_score'] <=> $b['reliability_score']; // Ascending
                }
                if ($a['downtime_hours'] != $b['downtime_hours']) {
                    return $b['downtime_hours'] <=> $a['downtime_hours']; // Descending
                }
                return $b['breakdowns'] <=> $a['breakdowns']; // Descending
            })->first();

            if ($leastReliableRaw) {
                $leastReliableMachine = [
                    'machine' => $leastReliableRaw['machine'],
                    'downtime' => $leastReliableRaw['downtime_hours'] . ' Hrs',
                    'downtime_hours' => $leastReliableRaw['downtime_hours'],
                    'breakdowns' => $leastReliableRaw['breakdowns'],
                    'reliability_score' => $leastReliableRaw['reliability_score']
                ];
            }
        }

        // Avg Downtime / Breakdown
        $avgDowntimePerBreakdownHours = 0.00;
        if ($totalEvents > 0) {
            $avgDowntimePerBreakdownHours = round($totalDowntimeHours / $totalEvents, 2);
        }

        // 8. breakdown_trend
        $breakdownsByDate = (clone $dashboardQuery)
            ->selectRaw('DATE(breakdown_date_time) as date, COUNT(*) as count')
            ->groupBy(DB::raw('DATE(breakdown_date_time)'))
            ->pluck('count', 'date');

        $breakdownTrend = [];
        $tempDate = $trendStart->copy();
        $dayIndex = 1;
        while ($tempDate->lte($trendEnd)) {
            $dateString = $tempDate->toDateString();
            $count = $breakdownsByDate->get($dateString, 0);
            $breakdownTrend[] = [
                'label' => 'Day ' . $dayIndex,
                'date' => $dateString,
                'count' => $count
            ];
            $tempDate->addDay();
            $dayIndex++;
        }

        // 9. downtime_by_machine
        $downtimeByMachine = collect($machineStatsCalculated)
            ->sortByDesc('downtime_hours')
            ->map(function ($m) {
                return [
                    'machine' => $m['machine'],
                    'downtime_hours' => $m['downtime_hours']
                ];
            })
            ->values()
            ->all();

        // 10. category_analysis
        $categoryAnalysis = (clone $dashboardQuery)
            ->select('breakdown_type_id')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('breakdown_type_id')
            ->with('breakdownType')
            ->get()
            ->map(function ($stat) {
                return [
                    'breakdown_type_id' => $stat->breakdown_type_id,
                    'breakdown_type' => $stat->breakdownType ? $stat->breakdownType->breakdown_type : 'Other',
                    'count' => $stat->count
                ];
            })
            ->values()
            ->all();

        // 11. reliability_ranking
        $reliabilityRanking = collect($machineStatsCalculated)
            ->sortByDesc('reliability_score')
            ->map(function ($m) {
                return [
                    'machine' => $m['machine'],
                    'reliability_score' => $m['reliability_score']
                ];
            })
            ->values()
            ->all();

        return [
            'tickets' => $tickets,
            'dashboard' => [
                'open_tickets' => $openTickets,
                'closed_today' => $closedToday,
                'total_downtime_hours' => $totalDowntimeHours,
                'mttr_hours' => $mttrHours,
                'equipment_availability_percent' => $equipmentAvailabilityPercent,
                'total_events' => $totalEvents,
                'closed_tickets' => $closedTickets,
                'affected_machines' => $affectedMachines,
                'avg_downtime_per_breakdown_hours' => $avgDowntimePerBreakdownHours,
                'avg_downtime_per_breakdown_formatted' => $avgDowntimePerBreakdownHours . ' Hours',
                'most_reliable_machine' => $mostReliableMachine,
                'least_reliable_machine' => $leastReliableMachine,
                'breakdown_trend' => $breakdownTrend,
                'downtime_by_machine' => $downtimeByMachine,
                'category_analysis' => $categoryAnalysis,
                'reliability_ranking' => $reliabilityRanking,
            ]
        ];
    }

    /**
     * Create a new breakdown ticket.
     * Generates ticket_number, forces status = 'open', and persists.
     *
     * @param array $data
     * @return BreakdownTicket
     */
    public function create(array $data)
    {
        $year = date('Y');

        return DB::transaction(function () use ($data, $year) {
            $lastTicket = BreakdownTicket::whereYear('created_at', $year)
                ->orderBy('id', 'DESC')
                ->first();

            $sequence = 1;
            if ($lastTicket) {
                $parts = explode('-', $lastTicket->ticket_number);
                if (count($parts) === 3) {
                    $sequence = (int) $parts[2] + 1;
                }
            }

            $ticketNumber = 'BRK-' . $year . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);

            $data['ticket_number'] = $ticketNumber;

            if (!empty($data['downtime_end'])) {
                $downtimeStart = \Carbon\Carbon::parse($data['downtime_start']);
                $downtimeEnd = \Carbon\Carbon::parse($data['downtime_end']);
                $data['downtime_minutes'] = $downtimeEnd->diffInMinutes($downtimeStart);
                $data['status'] = 'closed';
                $data['resolved_by'] = auth()->id() ?? $data['reported_by'];
                $data['resolved_at'] = now();
            } else {
                $data['status'] = 'open';
                unset($data['downtime_end'], $data['downtime_minutes'], $data['resolved_by'], $data['resolved_at']);
            }

            return BreakdownTicket::create($data);
        });
    }

    /**
     * Find a ticket by ID or fail with a ModelNotFoundException.
     *
     * @param int $id
     * @return BreakdownTicket
     */
    public function find(int $id)
    {
        return BreakdownTicket::with(['equipment', 'equipmentName', 'shift', 'reporter', 'resolver.employee', 'equipmentAllocation', 'breakdownType'])
            ->findOrFail($id);
    }

    /**
     * Update an existing breakdown ticket.
     *
     * @param BreakdownTicket $ticket
     * @param array $data
     * @return BreakdownTicket
     */
    public function update(BreakdownTicket $ticket, array $data)
    {
        if ($ticket->status === 'closed') {
            throw new HttpResponseException(
                response()->json([
                    'status' => 403,
                    'message' => 'Closed incidents cannot be edited.',
                    'data' => null
                ], 403)
            );
        }

        // Never accept downtime_minutes or resolution metrics directly from client input
        unset($data['downtime_minutes'], $data['resolved_by'], $data['resolved_at']);

        if (!empty($data['downtime_end']) || (isset($data['status']) && $data['status'] === 'closed')) {
            $data['status'] = 'closed';
            $downtimeEnd = \Carbon\Carbon::parse($data['downtime_end'] ?? $ticket->downtime_end);
            $downtimeStart = isset($data['downtime_start']) ? \Carbon\Carbon::parse($data['downtime_start']) : $ticket->downtime_start;

            $data['downtime_minutes'] = $downtimeEnd->diffInMinutes($downtimeStart);
            $data['resolved_by'] = auth()->id();
            $data['resolved_at'] = now();
        }

        $ticket->update($data);
        $ticket->load(['equipment', 'equipmentName', 'shift', 'reporter', 'resolver.employee', 'equipmentAllocation', 'breakdownType']);

        return $ticket;
    }
}
