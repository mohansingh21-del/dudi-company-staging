<?php

namespace App\Services;

use App\Models\Delay;
use App\Models\DelayAuditLog;
use App\Models\ShiftPlan;
use App\Exceptions\ReadOnlyFieldMutationException;
use App\Exceptions\DelayNotFoundException;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DelayService
{
    /**
     * Generate unique Delay Reference Number.
     * Format: DLY-{year}-{6-digit sequence}
     *
     * @return string
     */
    public function generateDelayRefNo(): string
    {
        return DB::transaction(function () {
            $year = date('Y');
            
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

            return 'DLY-' . $year . '-' . str_pad($sequence, 6, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Create a new Delay entry.
     */
    public function createDelay(array $data, int $userId): Delay
    {
        return DB::transaction(function () use ($data, $userId) {
            $shiftPlan = ShiftPlan::with('shift')->findOrFail($data['shift_plan_id']);

            $averageProductionRate = (float) config('mining.average_production_rate_bcm_per_hour', 150.00);

            $durationMinutes = null;
            $estimatedProductionLoss = null;

            if (!empty($data['start_time']) && !empty($data['end_time'])) {
                $start = Carbon::parse($data['start_time']);
                $end = Carbon::parse($data['end_time']);
                if ($end->lt($start)) {
                    $end->addDay(); // handles overnight delays
                }
                $durationMinutes = $start->diffInMinutes($end);
                $durationHours = $durationMinutes / 60.0;
                $estimatedProductionLoss = $durationHours * $averageProductionRate;
            }

            $delayRefNo = $this->generateDelayRefNo();

            $delayLogDate = isset($data['delay_log_date']) 
                ? Carbon::parse($data['delay_log_date'])->format('Y-m-d H:i:s') 
                : ($shiftPlan->planning_date ? Carbon::parse($shiftPlan->planning_date)->format('Y-m-d H:i:s') : null);
            $shiftId = $data['shift_id'] ?? $shiftPlan->shift_id;

            $delayData = array_merge($data, [
                'delay_ref_no' => $delayRefNo,
                'shift_id' => $shiftId,
                'delay_log_date' => $delayLogDate,
                'shift_date' => $shiftPlan->planning_date ? Carbon::parse($shiftPlan->planning_date)->format('Y-m-d') : null,
                'shift_name' => $shiftPlan->shift->shift_name ?? 'Unknown',
                'duration_minutes' => $durationMinutes,
                'average_production_rate_per_hour' => $averageProductionRate,
                'estimated_production_loss_bcm' => $estimatedProductionLoss,
                'severity' => $this->calculateSeverity($durationMinutes),
                'created_by' => $userId,
            ]);

            return Delay::create($delayData);
        });
    }

    /**
     * Update an existing Delay entry.
     */
    public function updateDelay(int $id, array $data, int $userId): Delay
    {
        return DB::transaction(function () use ($id, $data, $userId) {
            $delay = Delay::find($id);
            if (!$delay) {
                throw new DelayNotFoundException("Delay Record Not Found");
            }

            // Resolve shift plan details if updated
            if (isset($data['shift_plan_id'])) {
                $shiftPlan = ShiftPlan::with('shift')->findOrFail($data['shift_plan_id']);
                $data['shift_date'] = $shiftPlan->planning_date ? Carbon::parse($shiftPlan->planning_date)->format('Y-m-d') : null;
                $data['shift_name'] = $shiftPlan->shift->shift_name ?? 'Unknown';
                
                if (!isset($data['shift_id'])) {
                    $data['shift_id'] = $shiftPlan->shift_id;
                }
                if (!isset($data['delay_log_date'])) {
                    $data['delay_log_date'] = $shiftPlan->planning_date ? Carbon::parse($shiftPlan->planning_date)->format('Y-m-d H:i:s') : null;
                }
            }

            if (isset($data['delay_log_date'])) {
                $data['delay_log_date'] = Carbon::parse($data['delay_log_date'])->format('Y-m-d H:i:s');
            }

            // Calculations if start_time or end_time are updated
            $startVal = isset($data['start_time']) ? $data['start_time'] : $delay->start_time;
            $endVal = array_key_exists('end_time', $data) ? $data['end_time'] : $delay->end_time;

            if ($startVal && $endVal) {
                $start = Carbon::parse($startVal);
                $end = Carbon::parse($endVal);
                if ($end->lt($start)) {
                    $end->addDay(); // handles overnight delays
                }
                $durationMinutes = $start->diffInMinutes($end);
                $durationHours = $durationMinutes / 60.0;
                $averageProductionRate = (float) ($delay->average_production_rate_per_hour ?? config('mining.average_production_rate_bcm_per_hour', 150.00));
                $estimatedProductionLoss = $durationHours * $averageProductionRate;

                $data['duration_minutes'] = $durationMinutes;
                $data['estimated_production_loss_bcm'] = $estimatedProductionLoss;
            } else {
                $data['duration_minutes'] = null;
                $data['estimated_production_loss_bcm'] = null;
            }

            $data['severity'] = $this->calculateSeverity($data['duration_minutes']);

            $delay->fill($data);

            $dirty = $delay->getDirty();
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $field => $newValue) {
                $oldValues[$field] = $delay->getOriginal($field);
                $newValues[$field] = $newValue;
            }

            $delay->updated_by = $userId;
            $delay->save();

            if (!empty($oldValues)) {
                DelayAuditLog::create([
                    'delay_id' => $delay->id,
                    'changed_by' => $userId,
                    'old_values' => $oldValues,
                    'new_values' => $newValues,
                ]);
            }

            return $delay;
        });
    }

    /**
     * Get single Delay record details.
     */
    public function getDelay(int $id): Delay
    {
        $delay = Delay::with([
            'shiftPlan.shift',
            'shiftPlan.site',
            'linkedBreakdown',
            'equipment',
            'equipmentName',
            'delayCategory',
            'creator.employee',
            'updater.employee',
            'auditLogs.changedBy.employee'
        ])->find($id);

        if (!$delay) {
            throw new DelayNotFoundException("Delay Record Not Found");
        }

        return $delay;
    }

    /**
     * List delay register with filters, search, and KPI summaries.
     */
    public function listRegister(array $filters): array
    {
        $query = Delay::with([
            'shiftPlan.shift',
            'shiftPlan.site',
            'linkedBreakdown',
            'equipment',
            'equipmentName',
            'delayCategory',
        ]);

        if (!empty($filters['date_from'])) {
            $query->where('shift_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('shift_date', '<=', $filters['date_to']);
        }

        if (isset($filters['shift_id'])) {
            $query->where('shift_id', $filters['shift_id']);
        }

        if (isset($filters['delay_category_id'])) {
            $query->where('delay_category_id', $filters['delay_category_id']);
        }

        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['equipment_id'])) {
            $query->where('equipment_id', $filters['equipment_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('delay_ref_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('remarks', 'like', "%{$search}%")
                  ->orWhere('delay_subcategory', 'like', "%{$search}%");
            });
        }

        // Summary Calculations (before pagination)
        $summaryQuery = clone $query;
        $totalDelayMinutes = (float) $summaryQuery->sum('duration_minutes');
        $totalDelayHours = round($totalDelayMinutes / 60.0, 2);
        $totalProductionLossBcm = round((float) $summaryQuery->sum('estimated_production_loss_bcm'), 2);
        $numberOfDelayEvents = $summaryQuery->count();

        // Calculate category-wise summary
        $categories = \App\Models\DelayCategory::where('is_active', true)->get()->keyBy('id');

        $categorySummaryQuery = \App\Models\Delay::query();
        $categorySummaryQuery->setQuery(clone $query->getQuery());
        $categorySummaryQuery->getQuery()->columns = null;
        $categorySummaryQuery->getQuery()->orders = null;
        $categorySummaryQuery->getQuery()->groups = null;
        $categorySummaryQuery->getQuery()->havings = null;

        $rawSummary = $categorySummaryQuery->groupBy('delay_category_id')
            ->selectRaw('delay_category_id, sum(duration_minutes) as total_minutes, sum(estimated_production_loss_bcm) as total_loss, count(*) as event_count')
            ->get()
            ->keyBy('delay_category_id');

        $allCategoryIds = $categories->keys()->concat($rawSummary->keys())->unique();

        $categorySummary = $allCategoryIds->map(function ($catId) use ($categories, $rawSummary) {
            $cat = $categories->get($catId) ?? \App\Models\DelayCategory::find($catId);
            $item = $rawSummary->get($catId);
            return [
                'delay_category_id' => $catId,
                'delay_category_name' => $cat ? $cat->delay_category : 'Unknown',
                'total_delay_hours' => $item ? round((float)$item->total_minutes / 60.0, 2) : 0.0,
                'total_production_loss_bcm' => $item ? round((float)$item->total_loss, 2) : 0.0,
                'number_of_delay_events' => $item ? $item->event_count : 0,
            ];
        })->values()->toArray();

        $largestDelayReason = null;
        if (!empty($categorySummary)) {
            $sorted = collect($categorySummary)->sortByDesc('total_delay_hours');
            $first = $sorted->first();
            if ($first && $first['total_delay_hours'] > 0) {
                $largestDelayReason = [
                    'delay_category_name' => $first['delay_category_name'],
                    'total_delay_hours' => $first['total_delay_hours'],
                ];
            }
        }

        $kpiSummary = [
            'total_delay_hours' => $totalDelayHours,
            'total_production_loss_bcm' => $totalProductionLossBcm,
            'number_of_delay_events' => $numberOfDelayEvents,
            'largest_delay_reason' => $largestDelayReason,
            'category_summary' => $categorySummary,
        ];

        $perPage = isset($filters['limit']) ? (int) $filters['limit'] : 20;
        $records = $query->latest()->paginate($perPage);

        return [
            'records' => $records,
            'kpi_summary' => $kpiSummary,
        ];
    }

    /**
     * Calculate severity based on duration in minutes.
     */
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
}
