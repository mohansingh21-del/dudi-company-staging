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

        // Filter by Date or Period
        $startDate = null;
        $endDate = null;
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $startDate = \Carbon\Carbon::parse($filters['start_date'])->startOfDay();
            $endDate = \Carbon\Carbon::parse($filters['end_date'])->endOfDay();
            $query->whereBetween('planning_date', [
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d')
            ]);
        } elseif (!empty($filters['period'])) {
            $refDate = !empty($filters['date'])
                ? \Carbon\Carbon::parse($filters['date'])
                : \Carbon\Carbon::now();

            $period = strtolower($filters['period']);
            if ($period === 'quarterly') {
                $startDate = $refDate->copy()->startOfQuarter()->startOfDay();
                $endDate = $refDate->copy()->endOfQuarter()->endOfDay();
            } elseif ($period === 'yearly' || $period === 'annual') {
                $startDate = $refDate->copy()->startOfYear()->startOfDay();
                $endDate = $refDate->copy()->endOfYear()->endOfDay();
            } else {
                // monthly
                $startDate = $refDate->copy()->startOfMonth()->startOfDay();
                $endDate = $refDate->copy()->endOfMonth()->endOfDay();
            }

            $query->whereBetween('planning_date', [
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d')
            ]);
        } elseif (!empty($filters['date'])) {
            $startDate = \Carbon\Carbon::parse($filters['date'])->startOfDay();
            $endDate = \Carbon\Carbon::parse($filters['date'])->endOfDay();
            $query->whereDate('planning_date', $filters['date']);
        } else {
            $now = \Carbon\Carbon::now();
            $startDate = $now->copy()->startOfMonth()->startOfDay();
            $endDate = $now->copy()->endOfMonth()->endOfDay();
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

        // Filter by Supervisor ID
        if (!empty($filters['supervisor_id'])) {
            $supervisorUserId = $this->resolveEmployeeToUserId($filters['supervisor_id'], 'supervisor');
            $query->where('supervisor_id', $supervisorUserId);
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

        $statsQuery = clone $query;
        $totalScheduledShifts = $statsQuery->count();
        $totalTargetBcm = round((float) $statsQuery->sum('target_bcm'), 2);
        $totalActualBcm = round((float) $statsQuery->sum('actual_bcm'), 2);
        $currentEfficiency = $totalTargetBcm > 0
            ? round(($totalActualBcm / $totalTargetBcm) * 100)
            : 0;

        $shiftIds = $statsQuery->pluck('shift_id')->unique()->toArray();
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

        $paginated = $query->latest('planning_date')->paginate($limit);

        return [
            'status' => 200,
            'message' => 'Shift plans fetched successfully.',
            'data' => $paginated,
            'summary' => [
                'total_scheduled_shifts' => $totalScheduledShifts,
                'active_personnel' => $activePersonnel,
                'target_bcm' => (string) $totalTargetBcm,
                'actual_bcm' => (string) $totalActualBcm,
                'current_efficiency' => $currentEfficiency,
            ]
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
        $shiftPlan = null;
        if ($id) {
            $shiftPlan = ShiftPlan::find($id);

            if (!$shiftPlan) {
                return [
                    'status' => 404,
                    'message' => 'Shift Plan not found.',
                    'data' => null,
                ];
            }
        }

        $planningDate = $data['planning_date'] ?? ($shiftPlan ? $shiftPlan->planning_date->format('Y-m-d') : null);
        $shiftId = $data['shift_id'] ?? ($shiftPlan ? $shiftPlan->shift_id : null);

        // Map employee IDs to user IDs
        if (isset($data['supervisor_id'])) {
            $data['supervisor_id'] = $this->resolveEmployeeToUserId($data['supervisor_id'], 'supervisor');
        }
        if (isset($data['site_incharge_id'])) {
            $data['site_incharge_id'] = $this->resolveEmployeeToUserId($data['site_incharge_id'], 'site-incharge');
        }

        if ($id) {
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
            'creator.employee',
            'equipmentAllocations.equipmentName.equipment'
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
            $supervisorUserId = $this->resolveEmployeeToUserId($filters['supervisor_id'], 'supervisor');
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
        $totalTargetBcm = round((float) $query->sum('target_bcm'), 2);
        $totalActualBcm = round((float) $query->sum('actual_bcm'), 2);
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
                'target_bcm' => (string) $totalTargetBcm,
                'actual_bcm' => (string) $totalActualBcm,
                'current_efficiency' => $currentEfficiency,
            ],
            'shift_plans' => $shiftPlans,
        ];
    }

    /**
     * Resolve employee ID to corresponding user ID via role_user table.
     * If the employee is not mapped, auto-create a user and map it.
     *
     * @param int $employeeId
     * @param string|null $fallbackRoleSlug
     * @return int|null
     */
    protected function resolveEmployeeToUserId($employeeId, $fallbackRoleSlug = null)
    {
        $employee = \App\Models\Employee::with('roleUser')->find($employeeId);
        if (!$employee) {
            return null;
        }

        if ($employee->roleUser && $employee->roleUser->user_id) {
            return $employee->roleUser->user_id;
        }

        // Generate a unique email using the employee code
        $cleanCode = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $employee->employee_code);
        $email = strtolower($cleanCode) . '@dudicoalmine.com';

        $user = \App\Models\User::where('email', $email)->first();
        if (!$user) {
            $user = \App\Models\User::create([
                'email' => $email,
                'password' => \Illuminate\Support\Facades\Hash::make('admin@123'),
                'is_active' => 1,
            ]);
        }

        // Identify the role to assign
        $roleId = $employee->designation_id;
        if (!$roleId && $fallbackRoleSlug) {
            $role = \App\Models\Role::where('slug', $fallbackRoleSlug)->first();
            if ($role) {
                $roleId = $role->id;
            }
        }

        if (!$roleId) {
            // Default fallback: Supervisor role (usually slug 'supervisor')
            $role = \App\Models\Role::where('slug', 'supervisor')->first();
            $roleId = $role ? $role->id : 3;
        }

        // Ensure RoleUser record exists
        $roleUser = \App\Models\RoleUser::where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->first();

        if (!$roleUser) {
            $roleUser = \App\Models\RoleUser::create([
                'user_id' => $user->id,
                'role_id' => $roleId,
            ]);
        }

        // Link the employee to this RoleUser
        $employee->update(['role_user_id' => $roleUser->id]);

        return $user->id;
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

    /**
     * Perform validation checks for publishing a shift plan.
     *
     * @param  int  $id
     * @return array
     */
    public function validatePublish($id)
    {
        $shiftPlan = ShiftPlan::find($id);

        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan not found.',
                'data' => null,
            ];
        }

        $preconditionPassed = $shiftPlan->status === 'draft';

        $hasExcavator = $shiftPlan->equipmentAllocations()->whereHas('equipmentName.equipment', function ($q) {
            $q->whereRaw('LOWER(name) = ?', ['excavator']);
        })->exists();

        $hasWorkforce = $shiftPlan->workforceDeployments()->active()->exists();
        $supervisorAssigned = !is_null($shiftPlan->supervisor_id);
        $siteInchargeAssigned = !is_null($shiftPlan->site_incharge_id);
        $targetBcmAvailable = !is_null($shiftPlan->target_bcm) && $shiftPlan->target_bcm > 0;

        $canPublish = $preconditionPassed
            && $hasExcavator
            && $hasWorkforce
            && $supervisorAssigned
            && $siteInchargeAssigned
            && $targetBcmAvailable;

        return [
            'status' => 200,
            'message' => 'Shift plan validation completed.',
            'data' => [
                'shift_plan_id' => $shiftPlan->id,
                'can_publish' => $canPublish,
                'current_status' => $shiftPlan->status,
                'validations' => [
                    'precondition_draft' => [
                        'status' => $preconditionPassed,
                        'message' => $preconditionPassed ? 'Shift is in Draft status.' : 'Shift plan must be in Draft status to be published.'
                    ],
                    'equipment_allocated' => [
                        'status' => $hasExcavator,
                        'message' => $hasExcavator ? 'At least one Excavator allocated.' : 'At Least One Excavator Must Be Allocated.'
                    ],
                    'workforce_deployed' => [
                        'status' => $hasWorkforce,
                        'message' => $hasWorkforce ? 'Workforce assigned to shift.' : 'No Workforce Assigned To Shift.'
                    ],
                    'supervisor_assigned' => [
                        'status' => $supervisorAssigned,
                        'message' => $supervisorAssigned ? 'Supervisor assigned.' : 'Supervisor must be assigned to the shift.'
                    ],
                    'site_incharge_assigned' => [
                        'status' => $siteInchargeAssigned,
                        'message' => $siteInchargeAssigned ? 'Site Incharge assigned.' : 'Site Incharge must be assigned to the shift.'
                    ],
                    'target_bcm_defined' => [
                        'status' => $targetBcmAvailable,
                        'message' => $targetBcmAvailable ? 'Production target (BCM) defined.' : 'Production target (BCM) must be defined.'
                    ]
                ]
            ]
        ];
    }

    /**
     * Publish a shift plan.
     *
     * @param  int  $id
     * @param  int  $userId
     * @return array
     */
    public function publish($id, $userId)
    {
        $shiftPlan = ShiftPlan::find($id);

        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan not found.',
                'data' => null,
            ];
        }

        $validationResult = $this->validatePublish($id);
        if ($validationResult['status'] !== 200) {
            return $validationResult;
        }

        $canPublish = $validationResult['data']['can_publish'];
        if (!$canPublish) {
            return [
                'status' => 422,
                'message' => 'Shift plan cannot be published due to validation errors.',
                'data' => $validationResult['data']
            ];
        }

        // Update status and record publication details
        $shiftPlan->update([
            'status' => 'in_progress',
            'published_by' => $userId,
            'published_at' => now(),
        ]);

        return [
            'status' => 200,
            'message' => 'Shift Published Successfully.',
            'data' => $shiftPlan,
        ];
    }
}
