<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ShiftPlan;
use App\Models\ShiftWorkforceDeployment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WorkforceDeploymentService
{
    /**
     * BR-SHFT-008: Auto-load all active employees from the shift's relay
     * into shift_workforce_deployments. Idempotent — skips already-deployed employees.
     *
     * Because the existing schema has no relay_id on shift_plans, the relay
     * group is passed explicitly (e.g. 'relay_1'). If not provided, loads
     * ALL active employees regardless of relay_shift.
     *
     * @param  int          $shiftPlanId
     * @param  string|null  $relayShift  e.g. 'relay_1', 'relay_2', 'relay_3', 'general'
     * @return array
     */
    public function loadRelayWorkforce($shiftPlanId, $relayShift = null, $limit = 15)
    {
        $shiftPlan = ShiftPlan::find($shiftPlanId);

        if (!$shiftPlan) {
            return [
                'status'  => 404,
                'message' => 'Shift Plan not found.',
                'data'    => [],
            ];
        }

        $planningDate = $shiftPlan->planning_date->format('Y-m-d');

        // Fetch employee IDs who are on approved leave on this planning date
        $onLeaveEmployeeIds = \App\Models\Leave::where('status', 'approved')
            ->whereDate('from_date', '<=', $planningDate)
            ->whereDate('to_date', '>=', $planningDate)
            ->pluck('employee_id')
            ->toArray();

        // Fetch employee IDs who are marked as absent, leave, or rest_day in processed attendance
        $absentEmployeeIds = \App\Models\AttendanceProcessed::whereDate('date', $planningDate)
            ->whereIn('attendance_status', ['absent', 'leave', 'rest_day'])
            ->pluck('employee_id')
            ->toArray();

        $unavailableEmployeeIds = array_unique(array_merge($onLeaveEmployeeIds, $absentEmployeeIds));

        // Get employees assigned to this shift_plan's shift_id on this date (excluding leave/absent)
        $employees = Employee::where('is_active', true)
            ->whereNotIn('id', $unavailableEmployeeIds)
            ->whereHas('shiftAssignments', function ($query) use ($shiftPlan, $planningDate) {
                $query->where('shift_id', $shiftPlan->shift_id)
                    ->where('from_date', '<=', $planningDate)
                    ->where(function ($sub) use ($planningDate) {
                        $sub->whereNull('to_date')
                            ->orWhere('to_date', '>=', $planningDate);
                    });
            })
            ->get();

        // Get all shift plan IDs on this planning date
        $sameDatePlanIds = ShiftPlan::whereDate('planning_date', $planningDate)
            ->pluck('id')
            ->toArray();

        // Get IDs already actively deployed on any shift plan on this date
        $alreadyDeployedIds = ShiftWorkforceDeployment::whereIn('shift_plan_id', $sameDatePlanIds)
            ->active()
            ->pluck('employee_id')
            ->toArray();

        // Get IDs that were previously removed from THIS shift plan — never auto-re-deploy them
        $removedFromThisPlanIds = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)
            ->where('status', 'removed')
            ->pluck('employee_id')
            ->toArray();

        $skipIds = array_unique(array_merge($alreadyDeployedIds, $removedFromThisPlanIds));

        $newDeployments = [];
        $userId = Auth::id();

        DB::transaction(function () use (
            $employees,
            $shiftPlanId,
            $skipIds,
            $userId,
            &$newDeployments
        ) {
            foreach ($employees as $employee) {
                // Idempotent: skip if already deployed or previously removed
                if (in_array($employee->id, $skipIds)) {
                    continue;
                }

                // Resolve designation name from the roles table
                $designationName = null;
                if ($employee->designation_id) {
                    $role = \App\Models\Role::find($employee->designation_id);
                    $designationName = $role ? $role->name : null;
                }

                $deployment = ShiftWorkforceDeployment::create([
                    'shift_plan_id' => $shiftPlanId,
                    'employee_id'   => $employee->id,
                    'relay_shift'   => $employee->relay_shift,
                    'designation'   => $designationName,
                    'is_borrowed'   => false,
                    'deployed_by'   => $userId,
                    'status'        => 'active',
                ]);

                $newDeployments[] = $deployment;
            }
        });

        // Return all active deployments (including previously existing ones)
        $allDeployments = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)
            ->active()
            ->with(['employee', 'employee.designation', 'employee.shiftAssignments.shift', 'assignedMachine.equipmentName'])
            ->get();

        $listResult = $this->getWorkforceList($shiftPlanId, $limit);

        return [
            'status'     => 200,
            'message'    => count($newDeployments) > 0
                ? count($newDeployments) . ' employee(s) deployed successfully.'
                : 'All eligible employees are already deployed.',
            'data'       => $listResult['data'],
            'stats'      => $listResult['stats'],
            'pagination' => $listResult['pagination'],
        ];
    }

    /**
     * Get employees available for borrowing into this shift.
     * Excludes anyone already active on ANY shift for the same planning_date.
     * Only gets employees assigned to other shifts (relays) on this planning_date.
     * @param  int          $shiftPlanId
     * @param  string|null  $search
     * @param  int|null     $shiftId
     * @return array
     */
    public function getAvailableEmployeesForBorrowing($shiftPlanId, $search = null, $shiftId = null)
    {
        $shiftPlan = ShiftPlan::find($shiftPlanId);
 
        if (!$shiftPlan) {
            return [
                'status'  => 404,
                'message' => 'Shift Plan not found.',
                'data'    => [],
            ];
        }
 
        $planningDate = $shiftPlan->planning_date->format('Y-m-d');
 
        // Find all shift_plan IDs for the same date
        $sameDatePlanIds = ShiftPlan::whereDate('planning_date', $planningDate)
            ->pluck('id')
            ->toArray();
 
        // Find employee IDs that are already actively deployed on any shift for this date
        $deployedEmployeeIds = ShiftWorkforceDeployment::whereIn('shift_plan_id', $sameDatePlanIds)
            ->active()
            ->pluck('employee_id')
            ->toArray();
 
        // Fetch employee IDs who are on approved leave on this planning date
        $onLeaveEmployeeIds = \App\Models\Leave::where('status', 'approved')
            ->whereDate('from_date', '<=', $planningDate)
            ->whereDate('to_date', '>=', $planningDate)
            ->pluck('employee_id')
            ->toArray();
 
        // Fetch employee IDs who are marked as absent, leave, or rest_day in processed attendance
        $absentEmployeeIds = \App\Models\AttendanceProcessed::whereDate('date', $planningDate)
            ->whereIn('attendance_status', ['absent', 'leave', 'rest_day'])
            ->pluck('employee_id')
            ->toArray();
 
        $unavailableEmployeeIds = array_unique(array_merge($onLeaveEmployeeIds, $absentEmployeeIds));
 
        // Query available employees assigned to OTHER shifts on this date (excluding leave/absent/already deployed)
        $query = Employee::where('is_active', true)
            ->whereNotIn('id', $unavailableEmployeeIds)
            ->whereHas('shiftAssignments', function ($q) use ($shiftPlan, $planningDate, $shiftId) {
                if ($shiftId) {
                    $q->where('shift_id', $shiftId);
                } else {
                    $q->where('shift_id', '!=', $shiftPlan->shift_id);
                }
                $q->where('from_date', '<=', $planningDate)
                    ->where(function ($sub) use ($planningDate) {
                        $sub->whereNull('to_date')
                            ->orWhere('to_date', '>=', $planningDate);
                    });
            })
            ->whereNotIn('id', $deployedEmployeeIds)
            ->with(['designation', 'shiftAssignments.shift']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('employee_code', 'LIKE', "%{$search}%");
            });
        }

        $employees = $query->get();

        $result = $employees->map(function ($emp) use ($planningDate) {
            $designationName = null;
            if ($emp->designation) {
                $designationName = $emp->designation->name;
            }

            // Find their home shift assignment on this date to get their original shift name
            $activeAssignment = $emp->shiftAssignments
                ->filter(function ($assignment) use ($planningDate) {
                    return $assignment->from_date <= $planningDate 
                        && (is_null($assignment->to_date) || $assignment->to_date >= $planningDate);
                })
                ->first();

            $shiftName = ($activeAssignment && $activeAssignment->shift)
                ? $activeAssignment->shift->shift_name
                : null;

            return [
                'employee_id'         => $emp->id,
                'employee_code'       => $emp->employee_code,
                'employee_name'       => $emp->name,
                'home_relay_shift'    => $emp->relay_shift,
                'shift_name'          => $shiftName, // The original shift name they are assigned to
                'designation'         => $designationName,
                'availability_status' => 'Available',
            ];
        });

        return [
            'status'  => 200,
            'message' => 'Available employees fetched successfully.',
            'data'    => $result->values(),
        ];
    }

    /**
     * BR-SHFT-010 + BR-SHFT-012: Borrow multiple employees from other relays into this shift.
     * Validates uniqueness per calendar date (EF-01) and employee availability (EF-02)
     * for each employee individually. Valid employees are inserted; invalid ones are
     * collected into an errors array so the frontend can show partial-success feedback.
     *
     * @param  int     $shiftPlanId
     * @param  array   $employeeIds
     * @param  string  $reason
     * @param  int     $userId
     * @return array
     */
    public function borrowEmployees($shiftPlanId, array $employeeIds, $reason, $userId, $limit = 15)
    {
        $shiftPlan = ShiftPlan::find($shiftPlanId);

        if (!$shiftPlan) {
            return [
                'status'  => 404,
                'message' => 'Shift Plan not found.',
                'data'    => null,
            ];
        }

        $planningDate = $shiftPlan->planning_date->format('Y-m-d');

        // Pre-fetch all shift_plan IDs for the same date (single query)
        $sameDatePlanIds = ShiftPlan::whereDate('planning_date', $planningDate)
            ->pluck('id')
            ->toArray();

        // Fetch active deployments for this date with shift info
        $existingDeployments = ShiftWorkforceDeployment::whereIn('shift_plan_id', $sameDatePlanIds)
            ->active()
            ->with(['shiftPlan.shift'])
            ->get()
            ->keyBy('employee_id');

        $alreadyDeployedIds = $existingDeployments->keys()->toArray();

        // Fetch employee IDs who are on approved leave on this planning date
        $onLeaveEmployeeIds = \App\Models\Leave::where('status', 'approved')
            ->whereDate('from_date', '<=', $planningDate)
            ->whereDate('to_date', '>=', $planningDate)
            ->pluck('employee_id')
            ->toArray();

        // Fetch employee IDs who are marked as absent, leave, or rest_day in processed attendance
        $absentRecords = \App\Models\AttendanceProcessed::whereDate('date', $planningDate)
            ->whereIn('attendance_status', ['absent', 'leave', 'rest_day'])
            ->get()
            ->keyBy('employee_id');

        $absentEmployeeIds = $absentRecords->keys()->toArray();

        $unavailableEmployeeIds = array_unique(array_merge($onLeaveEmployeeIds, $absentEmployeeIds));

        // Pre-fetch all requested employees with designation (single query)
        $employees = Employee::with('designation')
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        $deployments = [];
        $errors = [];

        DB::transaction(function () use (
            $employeeIds,
            $employees,
            $alreadyDeployedIds,
            $existingDeployments,
            $unavailableEmployeeIds,
            $onLeaveEmployeeIds,
            $absentRecords,
            $shiftPlanId,
            $reason,
            $userId,
            &$deployments,
            &$errors
        ) {
            foreach ($employeeIds as $employeeId) {
                $employee = isset($employees[$employeeId]) ? $employees[$employeeId] : null;

                // Employee not found
                if (!$employee) {
                    $errors[] = [
                        'employee_id' => $employeeId,
                        'message'     => 'Employee not found.',
                    ];
                    continue;
                }

                // EF-02: Check master-data availability or leave/absent status
                if (!$employee->is_active || in_array($employeeId, $unavailableEmployeeIds)) {
                    $reasonMessage = 'Selected Employee Is Not Available.';
                    if (in_array($employeeId, $onLeaveEmployeeIds)) {
                        $reasonMessage = 'Employee is on leave.';
                    } else {
                        $attRecord = isset($absentRecords[$employeeId]) ? $absentRecords[$employeeId] : null;
                        if ($attRecord) {
                            if ($attRecord->attendance_status === 'rest_day') {
                                $reasonMessage = 'Employee is on rest day.';
                            } elseif (in_array($attRecord->attendance_status, ['absent', 'leave'])) {
                                $reasonMessage = 'Employee is absent.';
                            }
                        }
                    }

                    $errors[] = [
                        'employee_id' => $employeeId,
                        'employee_code' => $employee->employee_code,
                        'employee_name' => $employee->name,
                        'message'     => $reasonMessage,
                    ];
                    continue;
                }

                // EF-01 / BR-SHFT-012: Already deployed on any shift this date
                if (in_array($employeeId, $alreadyDeployedIds)) {
                    $existingDeployment = isset($existingDeployments[$employeeId]) ? $existingDeployments[$employeeId] : null;
                    $assignedShiftName = ($existingDeployment && $existingDeployment->shiftPlan && $existingDeployment->shiftPlan->shift)
                        ? $existingDeployment->shiftPlan->shift->shift_name
                        : 'Unknown';

                    $errors[] = [
                        'employee_id' => $employeeId,
                        'employee_code' => $employee->employee_code,
                        'employee_name' => $employee->name,
                        'message'     => 'Employee Already Assigned To Shift: ' . $assignedShiftName,
                    ];
                    continue;
                }

                // Resolve designation name
                $designationName = null;
                if ($employee->designation) {
                    $designationName = $employee->designation->name;
                }

                $deployment = ShiftWorkforceDeployment::create([
                    'shift_plan_id'    => $shiftPlanId,
                    'employee_id'      => $employee->id,
                    'relay_shift'      => $employee->relay_shift,
                    'home_relay_shift' => $employee->relay_shift,
                    'designation'      => $designationName,
                    'is_borrowed'      => true,
                    'borrowing_reason' => $reason,
                    'borrowed_by'      => $userId,
                    'borrowed_at'      => now(),
                    'deployed_by'      => $userId,
                    'status'           => 'active',
                ]);

                $deployments[] = $deployment;

                // Track this ID so subsequent duplicates in the same batch are caught
                $alreadyDeployedIds[] = $employeeId;
            }
        });

        // If ALL failed, return 422
        if (empty($deployments) && !empty($errors)) {
            return [
                'status'  => 422,
                'message' => count($errors) === 1
                    ? $errors[0]['message']
                    : count($errors) . ' employee(s) could not be borrowed.',
                'data'    => ['errors' => $errors],
            ];
        }

        // Reload with relationships
        $deploymentIds = array_map(function ($d) { return $d->id; }, $deployments);

        $loaded = ShiftWorkforceDeployment::whereIn('id', $deploymentIds)
            ->with(['employee', 'employee.designation', 'employee.shiftAssignments.shift', 'assignedMachine.equipmentName'])
            ->get();

        $successCount = count($deployments);
        $message = $successCount . ' employee(s) borrowed successfully.';

        if (!empty($errors)) {
            $message .= ' ' . count($errors) . ' employee(s) skipped.';
        }

        $listResult = $this->getWorkforceList($shiftPlanId, $limit);

        return [
            'status'     => 201,
            'message'    => $message,
            'data'       => $listResult['data'],
            'errors'     => $errors,
            'stats'      => $listResult['stats'],
            'pagination' => $listResult['pagination'],
        ];
    }

    /**
     * Soft-remove a deployment (status = 'removed') for audit trail.
     *
     * @param  int     $deploymentId
     * @param  string  $reason
     * @return array
     */
    public function removeDeployment($deploymentId, $reason, $limit = 15)
    {
        $deployment = ShiftWorkforceDeployment::find($deploymentId);

        if (!$deployment) {
            return [
                'status'  => 404,
                'message' => 'Deployment not found.',
                'data'    => null,
            ];
        }

        if ($deployment->status === 'removed') {
            return [
                'status'  => 422,
                'message' => 'Deployment has already been removed.',
                'data'    => null,
            ];
        }

        $shiftPlan = $deployment->shiftPlan;

        $deployment->update([
            'status'         => 'removed',
            'removed_reason' => $reason,
        ]);

        $listResult = $this->getWorkforceList($shiftPlan->id, $limit);

        return [
            'status'     => 200,
            'message'    => 'Deployment removed successfully.',
            'data'       => $listResult['data'],
            'stats'      => $listResult['stats'],
            'pagination' => $listResult['pagination'],
        ];
    }

    /**
     * BR-SHFT-011: Get workforce summary for a shift plan.
     * Borrowed employees ARE counted in totals.
     *
     * @param  int  $shiftPlanId
     * @return array
     */
    public function getWorkforceSummary($shiftPlanId)
    {
        $shiftPlan = ShiftPlan::find($shiftPlanId);

        if (!$shiftPlan) {
            return [
                'status'  => 404,
                'message' => 'Shift Plan not found.',
                'data'    => null,
            ];
        }

        $planningDate = $shiftPlan->planning_date->format('Y-m-d');

        // Fetch employee IDs who are on approved leave on this planning date
        $onLeaveEmployeeIds = \App\Models\Leave::where('status', 'approved')
            ->whereDate('from_date', '<=', $planningDate)
            ->whereDate('to_date', '>=', $planningDate)
            ->pluck('employee_id')
            ->toArray();

        $baseQuery = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)
            ->active()
            ->whereNotIn('employee_id', $onLeaveEmployeeIds);

        $totalDeployed  = (clone $baseQuery)->count();
        $regularCount   = (clone $baseQuery)->regular()->count();
        $borrowedCount  = (clone $baseQuery)->borrowed()->count();

        return [
            'status'  => 200,
            'message' => 'Workforce summary fetched successfully.',
            'data'    => [
                'total_deployed'  => $totalDeployed,
                'regular_count'   => $regularCount,
                'borrowed_count'  => $borrowedCount,
            ],
        ];
    }

    /**
     * Get paginated workforce list for a shift plan.
     * N+1 safe with eager loading.
     *
     * @param  int    $shiftPlanId
     * @param  int    $limit
     * @return array
     */
    public function getWorkforceList($shiftPlanId, $limit = 15)
    {
        $shiftPlan = ShiftPlan::with('shift')->find($shiftPlanId);

        if (!$shiftPlan) {
            return [
                'status'  => 404,
                'message' => 'Shift Plan not found.',
                'data'    => null,
            ];
        }

        $stats = $this->calculateStats($shiftPlan);

        $planningDate = $shiftPlan->planning_date->format('Y-m-d');

        // Fetch employee IDs who are on approved leave on this planning date
        $onLeaveEmployeeIds = \App\Models\Leave::where('status', 'approved')
            ->whereDate('from_date', '<=', $planningDate)
            ->whereDate('to_date', '>=', $planningDate)
            ->pluck('employee_id')
            ->toArray();

        // ── Paginated List ─────────────────────────────────────────
        $paginated = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)
            ->active()
            ->whereNotIn('employee_id', $onLeaveEmployeeIds)
            ->with([
                'employee',
                'employee.designation',
                'employee.shiftAssignments.shift',
                'assignedMachine.equipmentName',
            ])
            ->orderBy('is_borrowed', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        return [
            'status'  => 200,
            'message' => 'Workforce list fetched successfully.',
            'data'    => $this->formatDeployments($paginated->items(), $shiftPlan),
            'stats'   => $stats,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
            ],
        ];
    }

    /**
     * Map deployment models to standard array format with detailed attributes.
     */
    private function formatDeployments($deployments, $shiftPlan)
    {
        $planningDate = $shiftPlan->planning_date->format('Y-m-d');
        $shiftName = $shiftPlan->shift ? $shiftPlan->shift->shift_name : null;

        return collect($deployments)->map(function ($dep) use ($planningDate, $shiftName) {
            $employee = $dep->employee;
            $machine  = $dep->assignedMachine;

            $designationName = null;
            if ($employee && $employee->designation) {
                $designationName = $employee->designation->name;
            }

            $machineName = null;
            if ($machine && $machine->equipmentName) {
                $machineName = $machine->equipmentName->name;
            }

            // Find their home shift assignment on this date to get their original shift name
            $activeAssignment = null;
            if ($employee && $employee->relationLoaded('shiftAssignments')) {
                $activeAssignment = $employee->shiftAssignments
                    ->filter(function ($assignment) use ($planningDate) {
                        return $assignment->from_date <= $planningDate 
                            && (is_null($assignment->to_date) || $assignment->to_date >= $planningDate);
                    })
                    ->first();
            }

            $homeShiftName = ($activeAssignment && $activeAssignment->shift) 
                ? $activeAssignment->shift->shift_name 
                : null;

            return [
                'id'                => $dep->id,
                'employee_id'       => $dep->employee_id,
                'employee_name'     => $employee ? $employee->name : null,
                'employee_code'     => $employee ? $employee->employee_code : null,
                'designation'       => $dep->designation ? $dep->designation : $designationName,
                'relay_shift'       => $dep->relay_shift,
                'shift_name'        => $shiftName, // The shift of the current shift plan
                'home_shift_name'   => $homeShiftName, // The home shift of the employee on this date
                'assigned_machine'  => $machineName,
                'is_borrowed'       => (bool) $dep->is_borrowed,
                'home_relay_shift'  => $dep->home_relay_shift,
                'borrowing_reason'  => $dep->borrowing_reason,
                'status'            => $dep->status,
            ];
        })->values()->all();
    }

    /**
     * Helper to compute real-time workforce statistics for a shift plan.
     */
    private function calculateStats($shiftPlan)
    {
        $planningDate = $shiftPlan->planning_date->format('Y-m-d');
        $shiftPlanId = $shiftPlan->id;

        // Get active employee IDs assigned to this shift on this planning date
        $shiftEmployeeIds = Employee::where('is_active', true)
            ->whereHas('shiftAssignments', function ($q) use ($shiftPlan, $planningDate) {
                $q->where('shift_id', $shiftPlan->shift_id)
                    ->where('from_date', '<=', $planningDate)
                    ->where(function ($sub) use ($planningDate) {
                        $sub->whereNull('to_date')
                            ->orWhere('to_date', '>=', $planningDate);
                    });
            })
            ->pluck('id')
            ->toArray();

        // Get employee IDs on approved leave on this planning date
        $onLeaveEmployeeIds = \App\Models\Leave::where('status', 'approved')
            ->whereDate('from_date', '<=', $planningDate)
            ->whereDate('to_date', '>=', $planningDate)
            ->pluck('employee_id')
            ->toArray();

        // PLANNED: Total employees assigned to this shift on this date
        $plannedCount = count($shiftEmployeeIds);

        // LEAVE: Assigned employees who are on approved leave on this planning date
        $onLeaveCount = count(array_intersect($shiftEmployeeIds, $onLeaveEmployeeIds));

        // Active deployment base query (excluding employees on approved leave)
        $baseQuery = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)
            ->active()
            ->whereNotIn('employee_id', $onLeaveEmployeeIds);

        // PRESENT: Active deployments (regular, non-borrowed)
        $presentCount = (clone $baseQuery)->regular()->count();

        // BORROWED: All borrowed deployments (including removed ones)
        $borrowedCount = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)
            ->borrowed()
            ->count();

        return [
            'planned'  => $plannedCount,
            'present'  => $presentCount,
            'leave'    => $onLeaveCount,
            'borrowed' => $borrowedCount,
        ];
    }
}

