<?php

namespace App\Services;

use App\Models\ShiftPlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShiftPlanService
{
    /**
     * List all shift plans with pagination and filters.
     *
     * @param  array  $filters
     * @return array
     */
    public function listShiftPlans(array $filters)
    {
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 10;

        $query = ShiftPlan::with([
            'shift',
            'site',
            'supervisor.employee',
            'siteIncharge.employee',
            'creator.employee'
        ]);

        // Filter by Date
        if (!empty($filters['date'])) {
            $query->whereDate('planning_date', $filters['date']);
        }

        // Filter by Shift ID
        if (!empty($filters['shift_id'])) {
            $query->where('shift_id', $filters['shift_id']);
        }

        // Filter by Site ID
        if (!empty($filters['site_id'])) {
            $query->where('site_id', $filters['site_id']);
        }

        // Filter by Status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Search Query
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('planning_date', 'LIKE', "%{$search}%")
                    ->orWhereHas('shift', function ($sh) use ($search) {
                        $sh->where('shift_name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('site', function ($si) use ($search) {
                        $si->where('site_name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('supervisor.employee', function ($emp) use ($search) {
                        $emp->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('employee_code', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('siteIncharge.employee', function ($emp) use ($search) {
                        $emp->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('employee_code', 'LIKE', "%{$search}%");
                    });
            });
        }

        $paginated = $query->latest('planning_date')->paginate($limit);

        return [
            'status' => 200,
            'message' => 'Shift plans fetched successfully.',
            'data' => $paginated,
        ];
    }

    /**
     * Create or Update a shift plan.
     *
     * @param  array  $data
     * @param  int|null  $id
     * @return array
     */
    public function saveShiftPlan(array $data, $id = null)
    {
        // Map employee IDs to user IDs
        if (isset($data['supervisor_id'])) {
            $data['supervisor_id'] = $this->resolveEmployeeToUserId($data['supervisor_id']);
        }
        if (isset($data['site_incharge_id'])) {
            $data['site_incharge_id'] = $this->resolveEmployeeToUserId($data['site_incharge_id']);
        }

        if ($id) {
            $shiftPlan = ShiftPlan::find($id);

            if (!$shiftPlan) {
                return [
                    'status' => 404,
                    'message' => 'Shift Plan not found.',
                    'data' => null,
                ];
            }

            $shiftPlan->update($data);

            return [
                'status' => 200,
                'message' => 'Shift Plan updated successfully.',
                'data' => $shiftPlan,
            ];
        } else {
            $data['created_by'] = Auth::id();
            $data['status'] = 'draft';
            $data['equipment_count'] = 0;

            // Auto-generate unique Shift Reference Number
            $datePart = \Carbon\Carbon::parse($data['planning_date'])->format('Ymd');
            $siteId = $data['site_id'];
            $shiftId = $data['shift_id'];

            $referenceNo = 'SP-' . $datePart . '-' . $siteId . '-' . $shiftId . '-' . strtoupper(\Illuminate\Support\Str::random(4));

            while (ShiftPlan::where('reference_no', $referenceNo)->exists()) {
                $referenceNo = 'SP-' . $datePart . '-' . $siteId . '-' . $shiftId . '-' . strtoupper(\Illuminate\Support\Str::random(4));
            }

            $data['reference_no'] = $referenceNo;

            $shiftPlan = ShiftPlan::create($data);

            return [
                'status' => 201,
                'message' => 'Shift Plan created successfully.',
                'data' => $shiftPlan,
            ];
        }
    }

    /**
     * Create a new shift plan.
     *
     * @param  array  $data
     * @return array
     */
    public function createShiftPlan(array $data)
    {
        return $this->saveShiftPlan($data);
    }

    /**
     * Get a single shift plan.
     *
     * @param  int  $id
     * @return array
     */
    public function getShiftPlan($id)
    {
        $shiftPlan = ShiftPlan::with([
            'shift',
            'site',
            'supervisor.employee',
            'siteIncharge.employee',
            'creator.employee'
        ])->find($id);

        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan not found.',
                'data' => null,
            ];
        }

        return [
            'status' => 200,
            'message' => 'Shift Plan retrieved successfully.',
            'data' => $shiftPlan,
        ];
    }

    /**
     * Update an existing shift plan.
     *
     * @param  int    $id
     * @param  array  $data
     * @return array
     */
    public function updateShiftPlan($id, array $data)
    {
        return $this->saveShiftPlan($data, $id);
    }

    /**
     * Get the shift overview with stats and list of shift plans.
     *
     * @param  array  $filters
     * @return array
     */
    public function getShiftOverview(array $filters)
    {
        $startDate = null;
        $endDate = null;

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $startDate = \Carbon\Carbon::parse($filters['start_date'])->startOfDay();
            $endDate = \Carbon\Carbon::parse($filters['end_date'])->endOfDay();
        } else {
            $period = $filters['period'] ?? 'monthly';
            $now = \Carbon\Carbon::now();

            if ($period === 'quarterly') {
                $startDate = $now->copy()->startOfQuarter()->startOfDay();
                $endDate = $now->copy()->endOfQuarter()->endOfDay();
            } elseif ($period === 'annual') {
                $startDate = $now->copy()->startOfYear()->startOfDay();
                $endDate = $now->copy()->endOfYear()->endOfDay();
            } else {
                // monthly
                $startDate = $now->copy()->startOfMonth()->startOfDay();
                $endDate = $now->copy()->endOfMonth()->endOfDay();
            }
        }

        $query = ShiftPlan::query()->whereBetween('planning_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['site_id'])) {
            $query->where('site_id', $filters['site_id']);
        }

        if (!empty($filters['supervisor_id'])) {
            $supervisorUserId = $this->resolveEmployeeToUserId($filters['supervisor_id']);
            $query->where('supervisor_id', $supervisorUserId);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('reference_no', 'LIKE', "%{$search}%")
                    ->orWhereHas('shift', function ($sub) use ($search) {
                        $sub->where('shift_name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('site', function ($sub) use ($search) {
                        $sub->where('site_name', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Calculate stats on the filtered query (before pagination)
        $totalScheduledShifts = $query->count();
        $totalTargetBcm = (float) $query->sum('target_bcm');
        $totalActualBcm = (float) $query->sum('actual_bcm');
        $currentEfficiency = $totalTargetBcm > 0
            ? round(($totalActualBcm / $totalTargetBcm) * 100)
            : 0;

        // Get unique shifts in this query to calculate active personnel
        $shiftIds = $query->pluck('shift_id')->unique()->toArray();
        $activePersonnel = 0;
        if (!empty($shiftIds)) {
            $activePersonnel = \App\Models\EmployeeShiftAssignment::whereIn('shift_id', $shiftIds)
                ->where(function ($q) use ($startDate, $endDate) {
                    $startStr = $startDate->format('Y-m-d');
                    $endStr = $endDate->format('Y-m-d');
                    $q->where('from_date', '<=', $endStr)
                        ->where(function ($sub) use ($startStr) {
                            $sub->whereNull('to_date')
                                ->orWhere('to_date', '>=', $startStr);
                        });
                })
                ->distinct('employee_id')
                ->count('employee_id');
        }

        $limit = $filters['limit'] ?? 10;
        $shiftPlans = $query->with(['shift', 'site', 'supervisor', 'siteIncharge', 'creator'])
            ->latest('planning_date')
            ->paginate($limit);

        return [
            'stats' => [
                'total_scheduled_shifts' => $totalScheduledShifts,
                'active_personnel' => $activePersonnel,
                'target_bcm' => $totalTargetBcm,
                'actual_bcm' => $totalActualBcm,
                'current_efficiency' => $currentEfficiency,
            ],
            'shift_plans' => $shiftPlans,
        ];
    }

    /**
     * Resolve employee ID to corresponding user ID via role_user table.
     *
     * @param int $employeeId
     * @return int|null
     */
    protected function resolveEmployeeToUserId($employeeId)
    {
        $employee = \App\Models\Employee::with('roleUser')->find($employeeId);
        if ($employee && $employee->roleUser) {
            return $employee->roleUser->user_id;
        }
        return null;
    }

    /**
     * Delete an existing shift plan.
     *
     * @param  int  $id
     * @return array
     */
    public function deleteShiftPlan($id)
    {
        $shiftPlan = ShiftPlan::find($id);

        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan not found.',
                'data' => null,
            ];
        }

        DB::transaction(function () use ($shiftPlan) {
            // Delete allocations as well, in case DB cascade didn't handle it
            $shiftPlan->equipmentAllocations()->delete();
            $shiftPlan->delete();
        });

        return [
            'status' => 200,
            'message' => 'Shift Plan deleted successfully.',
            'data' => [],
        ];
    }

    /**
     * Update status of an existing shift plan.
     *
     * @param  int     $id
     * @param  string  $status
     * @return array
     */
    public function updateStatus($id, $status)
    {
        $shiftPlan = ShiftPlan::find($id);

        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan not found.',
                'data' => null,
            ];
        }

        $shiftPlan->update(['status' => $status]);

        return [
            'status' => 200,
            'message' => 'Shift Plan status updated successfully.',
            'data' => $shiftPlan,
        ];
    }
}
