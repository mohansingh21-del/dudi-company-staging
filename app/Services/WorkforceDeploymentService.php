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
     * Shift plan IDs on this date that still hold a claim on their deployed employees.
     *
     * A closed shift plan has already been worked and its summary frozen, so a
     * deployment sitting on it no longer occupies the employee: once their shift is
     * overridden onto another shift for the same date they must still be loadable
     * into that shift's plan. Open plans keep blocking, so nobody ends up standing
     * on two live shifts at once.
     *
     * @param  string    $planningDate
     * @param  int|null  $alwaysInclude  plan that counts as occupying even when closed
     * @return array
     */
    private function occupyingPlanIds($planningDate, $alwaysInclude = null)
    {
        $planIds = ShiftPlan::whereDate('planning_date', $planningDate)
            ->notClosed()
            ->pluck('id')
            ->toArray();

        if ($alwaysInclude && !in_array((int) $alwaysInclude, array_map('intval', $planIds), true)) {
            $planIds[] = (int) $alwaysInclude;
        }

        return $planIds;
    }

    /**
     * BR-SHFT-008: Auto-load all active employees from the shift's relay
     * into shift_workforce_deployments. Idempotent — skips already-deployed employees.
     *
     * Because the existing schema has no relay_id on shift_plans, the relay
     * group is passed explicitly (e.g. 'relay_1'). If not provided, loads
     * ALL active employees regardless of relay_shift.
     *
     * @param  int          $shiftPlanId
     * @param  int|string|null  $relayId  e.g. Relay ID or Relay Name
     * @return array
     */
    public function loadRelayWorkforce($shiftPlanId, $relayId = null, $limit = 15)
    {
        $shiftPlan = ShiftPlan::with('shift')->find($shiftPlanId);

        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan not found.',
                'data' => [],
            ];
        }

        if ($shiftPlan->planning_date->greaterThan(\Carbon\Carbon::today())) {
            return [
                'status' => 422,
                'message' => 'Cannot deploy workforce before the planned date of the shift.',
                'data' => [],
            ];
        }

        $planningDate = $shiftPlan->planning_date->format('Y-m-d');

        // Clean up any existing active deployments for this shift plan that are no longer assigned to this shift due to rotation
        $existingDeployments = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)
            ->active()
            ->get();
        foreach ($existingDeployments as $dep) {
            if (!$dep->is_borrowed) {
                $emp = Employee::find($dep->employee_id);
                if ($emp && $emp->getShiftIdForDate($planningDate) != $shiftPlan->shift_id) {
                    $dep->delete();
                }
            }
        }

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

        // Get active employees not unavailable
        $employeeQuery = Employee::where('is_active', true)
            ->whereNotIn('id', $unavailableEmployeeIds);

        if ($relayId) {
            if (is_numeric($relayId)) {
                $employeeQuery->where('relay_id', $relayId);
            } else {
                $employeeQuery->whereHas('relay', function ($q) use ($relayId) {
                    $q->where('name', $relayId);
                });
            }
        }

        $activeEmployees = $employeeQuery->get();

        // Filter by shift on this date
        $employees = $activeEmployees->filter(function ($employee) use ($planningDate, $shiftPlan) {
            return $employee->getShiftIdForDate($planningDate) == $shiftPlan->shift_id;
        });

        // Get IDs already actively deployed on any still-open shift plan on this date.
        // This plan is always counted so a repeat call stays idempotent on its own rows.
        $alreadyDeployedIds = ShiftWorkforceDeployment::whereIn(
                'shift_plan_id',
                $this->occupyingPlanIds($planningDate, $shiftPlanId)
            )
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

        DB::transaction(function () use ($employees, $shiftPlanId, $skipIds, $userId, &$newDeployments) {
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
                    'employee_id' => $employee->id,
                    'relay_id' => $employee->relay_id,
                    'designation' => $designationName,
                    'is_borrowed' => false,
                    'deployed_by' => $userId,
                    'status' => 'active',
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
            'status' => 200,
            'message' => count($newDeployments) > 0
                ? count($newDeployments) . ' employee(s) deployed successfully.'
                : 'All eligible employees are already deployed.',
            'data' => $listResult['data'],
            'stats' => $listResult['stats'],
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
        $shiftPlan = ShiftPlan::with('shift')->find($shiftPlanId);

        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan not found.',
                'data' => [],
            ];
        }

        if ($shiftPlan->planning_date->greaterThan(\Carbon\Carbon::today())) {
            return [
                'status' => 422,
                'message' => 'Cannot view available employees for borrowing before the planned date of the shift.',
                'data' => [],
            ];
        }

        $planningDate = $shiftPlan->planning_date->format('Y-m-d');

        if ($shiftId) {
            if ($shiftId == $shiftPlan->shift_id) {
                return [
                    'status' => 422,
                    'message' => 'Cannot borrow employees from the same shift.',
                    'data' => [],
                ];
            }

            $selectedShift = \App\Models\Shift::find($shiftId);
            if ($selectedShift) {
                $validationError = $this->validateBorrowingFromShift($selectedShift, $shiftPlan);
                if ($validationError) {
                    return [
                        'status' => 422,
                        'message' => $validationError,
                        'data' => [],
                    ];
                }
            }
        }

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

        // Query active employees who are not unavailable and not already deployed
        $query = Employee::where('is_active', true)
            ->whereNotIn('id', $unavailableEmployeeIds)
            ->whereNotIn('id', $deployedEmployeeIds)
            ->with(['designation']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('employee_code', 'LIKE', "%{$search}%");
            });
        }

        $allEmployees = $query->get();

        // Filter by shift on this date
        $employees = $allEmployees->filter(function ($emp) use ($shiftPlan, $planningDate, $shiftId) {
            $empShiftId = $emp->getShiftIdForDate($planningDate);
            if (is_null($empShiftId)) {
                return false;
            }
            if ($shiftId) {
                return $empShiftId == $shiftId;
            } else {
                return $empShiftId != $shiftPlan->shift_id;
            }
        });

        // Exclude employees whose home shift is invalid for borrowing
        $employees = $employees->filter(function ($emp) use ($shiftPlan, $planningDate) {
            $homeShift = $emp->getShiftForDate($planningDate);
            if ($homeShift) {
                $validationError = $this->validateBorrowingFromShift($homeShift, $shiftPlan);
                if ($validationError) {
                    return false;
                }
            }
            return true;
        });

        $result = $employees->map(function ($emp) use ($planningDate) {
            $designationName = null;
            if ($emp->designation) {
                $designationName = $emp->designation->name;
            }

            $homeShift = $emp->getShiftForDate($planningDate);
            $shiftName = $homeShift ? $homeShift->shift_name : null;

            return [
                'employee_id' => $emp->id,
                'employee_code' => $emp->employee_code,
                'employee_name' => $emp->name,
                'home_relay_id' => $emp->relay_id,
                'home_relay_shift' => optional($emp->relay)->name,
                'shift_name' => $shiftName, // The original shift name they are assigned to
                'designation' => $designationName,
                'availability_status' => 'Available',
            ];
        });

        return [
            'status' => 200,
            'message' => 'Available employees fetched successfully.',
            'data' => $result->values(),
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
        $shiftPlan = ShiftPlan::with('shift')->find($shiftPlanId);

        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan not found.',
                'data' => null,
            ];
        }

        if ($shiftPlan->planning_date->greaterThan(\Carbon\Carbon::today())) {
            return [
                'status' => 422,
                'message' => 'Cannot borrow employees before the planned date of the shift.',
                'data' => null,
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

        // Pre-fetch all requested employees with designation and shift assignments (single query)
        $employees = Employee::with(['designation', 'shiftAssignments.shift'])
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        $deployments = [];
        $errors = [];

        DB::transaction(function () use ($employeeIds, $employees, $alreadyDeployedIds, $existingDeployments, $unavailableEmployeeIds, $onLeaveEmployeeIds, $absentRecords, $shiftPlanId, $reason, $userId, $planningDate, $shiftPlan, &$deployments, &$errors) {
            foreach ($employeeIds as $employeeId) {
                $employee = isset($employees[$employeeId]) ? $employees[$employeeId] : null;

                // Employee not found
                if (!$employee) {
                    $errors[] = [
                        'employee_id' => $employeeId,
                        'message' => 'Employee not found.',
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
                        'message' => $reasonMessage,
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
                        'message' => 'Employee Already Assigned To Shift: ' . $assignedShiftName,
                    ];
                    continue;
                }

                // Check if home shift is invalid for borrowing
                $activeAssignment = null;
                if ($employee && $employee->relationLoaded('shiftAssignments')) {
                    $activeAssignment = $employee->shiftAssignments
                        ->filter(function ($assignment) use ($planningDate) {
                            return $assignment->from_date <= $planningDate
                                && (is_null($assignment->to_date) || $assignment->to_date >= $planningDate);
                        })
                        ->first();
                }

                if ($activeAssignment && $activeAssignment->shift) {
                    $validationError = $this->validateBorrowingFromShift($activeAssignment->shift, $shiftPlan);
                    if ($validationError) {
                        $errors[] = [
                            'employee_id' => $employeeId,
                            'employee_code' => $employee->employee_code,
                            'employee_name' => $employee->name,
                            'message' => $validationError,
                        ];
                        continue;
                    }
                }

                // Resolve designation name
                $designationName = null;
                if ($employee->designation) {
                    $designationName = $employee->designation->name;
                }

                $deployment = ShiftWorkforceDeployment::create([
                    'shift_plan_id' => $shiftPlanId,
                    'employee_id' => $employee->id,
                    'relay_id' => $employee->relay_id,
                    'home_relay_id' => $employee->relay_id,
                    'designation' => $designationName,
                    'is_borrowed' => true,
                    'borrowing_reason' => $reason,
                    'borrowed_by' => $userId,
                    'borrowed_at' => now(),
                    'deployed_by' => $userId,
                    'status' => 'active',
                ]);

                $deployments[] = $deployment;

                // Track this ID so subsequent duplicates in the same batch are caught
                $alreadyDeployedIds[] = $employeeId;
            }
        });

        // If ALL failed, return 422
        if (empty($deployments) && !empty($errors)) {
            return [
                'status' => 422,
                'message' => count($errors) === 1
                    ? $errors[0]['message']
                    : count($errors) . ' employee(s) could not be borrowed.',
                'data' => ['errors' => $errors],
            ];
        }

        // Reload with relationships
        $deploymentIds = array_map(function ($d) {
            return $d->id;
        }, $deployments);

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
            'status' => 201,
            'message' => $message,
            'data' => $listResult['data'],
            'errors' => $errors,
            'stats' => $listResult['stats'],
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
                'status' => 404,
                'message' => 'Deployment not found.',
                'data' => null,
            ];
        }

        if ($deployment->status === 'removed') {
            return [
                'status' => 422,
                'message' => 'Deployment has already been removed.',
                'data' => null,
            ];
        }

        $shiftPlan = $deployment->shiftPlan;

        $deployment->update([
            'status' => 'removed',
            'removed_reason' => $reason,
        ]);

        $listResult = $this->getWorkforceList($shiftPlan->id, $limit);

        return [
            'status' => 200,
            'message' => 'Deployment removed successfully.',
            'data' => $listResult['data'],
            'stats' => $listResult['stats'],
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
                'status' => 404,
                'message' => 'Shift Plan not found.',
                'data' => null,
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

        $excludeEmployeeIds = array_unique(array_merge($onLeaveEmployeeIds, $absentEmployeeIds));

        $baseQuery = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)
            ->active()
            ->whereNotIn('employee_id', $excludeEmployeeIds);

        $totalDeployed = (clone $baseQuery)->count();
        $regularCount = (clone $baseQuery)->regular()->count();
        $borrowedCount = (clone $baseQuery)->borrowed()->count();

        return [
            'status' => 200,
            'message' => 'Workforce summary fetched successfully.',
            'data' => [
                'total_deployed' => $totalDeployed,
                'regular_count' => $regularCount,
                'borrowed_count' => $borrowedCount,
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
                'status' => 404,
                'message' => 'Shift Plan not found.',
                'data' => null,
            ];
        }

        $planningDate = $shiftPlan->planning_date->format('Y-m-d');

        // Fetch all active employees
        $activeEmployees = Employee::where('is_active', true)
            ->with(['designation', 'shiftAssignments.shift'])
            ->get();

        // Filter those whose home shift on this date is this shift plan's shift
        $homeEmployees = $activeEmployees->filter(function ($emp) use ($planningDate, $shiftPlan) {
            return $emp->getShiftIdForDate($planningDate) == $shiftPlan->shift_id;
        });

        // Get all deployments for this shift plan (active or removed)
        $allDeployments = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)
            ->with(['employee', 'employee.designation', 'employee.shiftAssignments.shift', 'assignedMachine.equipmentName'])
            ->get();

        $activeDeployments = $allDeployments->filter(function ($d) {
            return $d->status === 'active';
        })->keyBy('employee_id');
        $removedDeployments = $allDeployments->filter(function ($d) {
            return $d->status === 'removed';
        })->keyBy('employee_id');

        // Get all active deployments on OTHER still-open shift plans for this date.
        // A deployment on a closed plan no longer occupies the employee, so it must
        // not report them as busy elsewhere here either.
        $otherDeployments = ShiftWorkforceDeployment::whereIn(
                'shift_plan_id',
                $this->occupyingPlanIds($planningDate)
            )
            ->where('shift_plan_id', '!=', $shiftPlanId)
            ->active()
            ->with('shiftPlan.shift')
            ->get()
            ->groupBy('employee_id');

        // Fetch leaves and attendance records
        $leaves = \App\Models\Leave::where('status', 'approved')
            ->whereDate('from_date', '<=', $planningDate)
            ->whereDate('to_date', '>=', $planningDate)
            ->get()
            ->keyBy('employee_id');

        $attendanceRecords = \App\Models\AttendanceProcessed::whereDate('date', $planningDate)
            ->get()
            ->keyBy('employee_id');

        // Build unified list of employees
        $unifiedEmployees = collect();
        $processedEmployeeIds = [];

        // 1. Add all home employees
        foreach ($homeEmployees as $emp) {
            $unifiedEmployees->push($emp);
            $processedEmployeeIds[] = $emp->id;
        }

        // 2. Add borrowed employees (both active and removed)
        foreach ($allDeployments as $dep) {
            if ($dep->is_borrowed && !in_array($dep->employee_id, $processedEmployeeIds)) {
                if ($dep->employee) {
                    $unifiedEmployees->push($dep->employee);
                    $processedEmployeeIds[] = $dep->employee_id;
                }
            }
        }

        // Map and format
        $formattedItems = $unifiedEmployees->map(function ($emp) use ($planningDate, $activeDeployments, $removedDeployments, $otherDeployments, $leaves, $attendanceRecords, $shiftPlan) {
            $empId = $emp->id;
            $activeDep = isset($activeDeployments[$empId]) ? $activeDeployments[$empId] : null;
            $removedDep = isset($removedDeployments[$empId]) ? $removedDeployments[$empId] : null;
            $dep = $activeDep ?: $removedDep; // use either active or removed deployment details

            // Determine status
            $status = 'Not Deployed';

            if (isset($leaves[$empId])) {
                $status = 'On Leave';
            } elseif (isset($attendanceRecords[$empId])) {
                $attStatus = $attendanceRecords[$empId]->attendance_status;
                if ($attStatus === 'present') {
                    $status = 'Present';
                } elseif ($attStatus === 'absent') {
                    $status = 'Absent';
                } elseif ($attStatus === 'rest_day') {
                    $status = 'Rest Day';
                } elseif ($attStatus === 'leave') {
                    $status = 'On Leave';
                } else {
                    $status = ucfirst(str_replace('_', ' ', $attStatus));
                }
            } elseif ($activeDep && $activeDep->is_borrowed) {
                $status = 'Borrowed';
            } elseif ($activeDep && !$activeDep->is_borrowed) {
                $status = 'Deployed';
            } elseif (isset($otherDeployments[$empId])) {
                $otherDep = $otherDeployments[$empId]->first();
                $otherShiftName = ($otherDep->shiftPlan && $otherDep->shiftPlan->shift)
                    ? $otherDep->shiftPlan->shift->shift_name
                    : 'Another Shift';

                if ($otherDep->is_borrowed) {
                    $status = 'Borrowed in ' . $otherShiftName;
                } else {
                    $status = 'Deployed in ' . $otherShiftName;
                }
            } elseif ($removedDep) {
                $status = 'Removed';
            }

            // Formatting fields
            $designationName = $emp->designation ? $emp->designation->name : null;
            $machineName = ($activeDep && $activeDep->assignedMachine && $activeDep->assignedMachine->equipmentName)
                ? $activeDep->assignedMachine->equipmentName->name
                : null;

            $homeShift = $emp->getShiftForDate($planningDate);
            $homeShiftName = $homeShift ? $homeShift->shift_name : null;

            return [
                'id' => $activeDep ? $activeDep->id : null,
                'employee_id' => $empId,
                'employee_name' => $emp->name,
                'employee_code' => $emp->employee_code,
                'designation' => $activeDep && $activeDep->designation ? $activeDep->designation : $designationName,
                'relay_id' => $activeDep ? $activeDep->relay_id : $emp->relay_id,
                'relay_shift' => $activeDep && $activeDep->relay ? $activeDep->relay->name : optional($emp->relay)->name,
                'shift_name' => $shiftPlan->shift ? $shiftPlan->shift->shift_name : null,
                'home_shift_name' => $homeShiftName,
                'assigned_machine' => $machineName,
                'is_borrowed' => $dep ? (bool) $dep->is_borrowed : false,
                'home_relay_id' => $dep ? $dep->home_relay_id : $emp->relay_id,
                'home_relay_shift' => $dep && $dep->homeRelay ? $dep->homeRelay->name : optional($emp->relay)->name,
                'borrowing_reason' => $dep ? $dep->borrowing_reason : null,
                'status' => $status,
            ];
        });

        // Compute stats from formatted items
        $stats = $this->calculateStats($shiftPlan, $formattedItems);

        // Sort items:
        // Weight 1: Deployed (non-borrowed)
        // Weight 2: Borrowed into this shift
        // Weight 3: Not Deployed
        // Weight 4: On Leave
        // Weight 5: Absent
        // Weight 6: Rest Day
        // Weight 7: Borrowed in other shift
        // Weight 8: Deployed in other shift
        // Weight 9: Removed
        $statusWeight = function ($item) {
            if ($item['id'] !== null) {
                return $item['is_borrowed'] ? 2 : 1;
            }
            if ($item['status'] === 'On Leave')
                return 4;
            if ($item['status'] === 'Absent')
                return 5;
            if ($item['status'] === 'Rest Day')
                return 6;
            if (strpos($item['status'], 'Borrowed in') === 0)
                return 7;
            if (strpos($item['status'], 'Deployed in') === 0)
                return 8;
            if ($item['status'] === 'Removed')
                return 9;
            return 3; // 'Not Deployed'
        };

        $sortedItems = $formattedItems->sortBy($statusWeight)->values();

        // Paginate the collection manually
        $currentPage = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage() ?: 1;
        $totalItems = $sortedItems->count();
        $perPage = $limit;
        $currentPageItems = $sortedItems->slice(($currentPage - 1) * $perPage, $perPage)->values()->all();

        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $currentPageItems,
            $totalItems,
            $perPage,
            $currentPage,
            ['path' => \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPath()]
        );

        return [
            'status' => 200,
            'message' => 'Workforce list fetched successfully.',
            'data' => $paginated->items(),
            'stats' => $stats,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
            ],
        ];
    }

    /**
     * Validate if borrowing from a shift is allowed.
     *
     * @param  \App\Models\Shift  $homeShift
     * @param  \App\Models\ShiftPlan  $targetShiftPlan
     * @return string|null
     */
    private function validateBorrowingFromShift($homeShift, $targetShiftPlan)
    {
        if (!$homeShift) {
            return null;
        }

        // Intra-shift check: if home shift is same as target shift, allow it
        if ($homeShift->id === $targetShiftPlan->shift_id) {
            return null;
        }

        $planningDate = $targetShiftPlan->planning_date->format('Y-m-d');

        // Check if the home shift has any plan on this date (any status)
        $homeShiftPlan = ShiftPlan::where('shift_id', $homeShift->id)
            ->whereDate('planning_date', $planningDate)
            ->first();

        // If the home shift is NOT planned for this date, employees are free — allow borrowing
        if (!$homeShiftPlan) {
            return null;
        }

        // 1. Check if the home shift plan is already in working phase (status is 'active')
        if ($homeShiftPlan->status === 'active') {
            return 'Cannot borrow employees from a shift that is already in the working phase.';
        }

        // 2. Block if the home shift and target shift timings overlap
        $targetShift = $targetShiftPlan->shift;
        if ($targetShift) {
            $targetStart = \Carbon\Carbon::parse($planningDate . ' ' . $targetShift->start_time);
            $targetEnd = \Carbon\Carbon::parse($planningDate . ' ' . $targetShift->end_time);
            if (\Carbon\Carbon::parse($targetShift->start_time)->greaterThanOrEqualTo(\Carbon\Carbon::parse($targetShift->end_time))) {
                $targetEnd->addDay();
            }

            $homeStart = \Carbon\Carbon::parse($planningDate . ' ' . $homeShift->start_time);
            $homeEnd = \Carbon\Carbon::parse($planningDate . ' ' . $homeShift->end_time);
            if (\Carbon\Carbon::parse($homeShift->start_time)->greaterThanOrEqualTo(\Carbon\Carbon::parse($homeShift->end_time))) {
                $homeEnd->addDay();
            }

            if ($targetStart->lessThan($homeEnd) && $homeStart->lessThan($targetEnd)) {
                return "Cannot borrow employees from {$homeShift->shift_name} because its timings overlap with {$targetShift->shift_name}.";
            }
        }

        return null;
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
            $machine = $dep->assignedMachine;

            $designationName = null;
            if ($employee && $employee->designation) {
                $designationName = $employee->designation->name;
            }

            $machineName = null;
            if ($machine && $machine->equipmentName) {
                $machineName = $machine->equipmentName->name;
            }

            $homeShiftName = null;
            if ($employee) {
                $homeShift = $employee->getShiftForDate($planningDate);
                $homeShiftName = $homeShift ? $homeShift->shift_name : null;
            }

            return [
                'id' => $dep->id,
                'employee_id' => $dep->employee_id,
                'employee_name' => $employee ? $employee->name : null,
                'employee_code' => $employee ? $employee->employee_code : null,
                'designation' => $dep->designation ? $dep->designation : $designationName,
                'relay_id' => $dep->relay_id,
                'relay_shift' => $dep->relay ? $dep->relay->name : null,
                'shift_name' => $shiftName, // The shift of the current shift plan
                'home_shift_name' => $homeShiftName, // The home shift of the employee on this date
                'assigned_machine' => $machineName,
                'is_borrowed' => (bool) $dep->is_borrowed,
                'home_relay_id' => $dep->home_relay_id,
                'home_relay_shift' => $dep->homeRelay ? $dep->homeRelay->name : null,
                'borrowing_reason' => $dep->borrowing_reason,
                'status' => $dep->status,
            ];
        })->values()->all();
    }

    /**
     * Helper to compute real-time workforce statistics for a shift plan.
     */
    private function calculateStats($shiftPlan, $formattedItems)
    {
        $plannedCount = 0;
        $presentCount = 0;
        $leaveCount = 0;
        $borrowedCount = 0;
        $absentCount = 0;
        $restDayCount = 0;
        $deployedCount = 0;
        $notDeployedCount = 0;
        $removedCount = 0;
        $deployedInOtherShiftCount = 0;
        $borrowedInOtherShiftCount = 0;

        foreach ($formattedItems as $item) {
            // Stats categorization
            if ($item['is_borrowed']) {
                $borrowedCount++;
            } else {
                if ($item['status'] === 'On Leave') {
                    $leaveCount++;
                } elseif ($item['status'] === 'Present') {
                    $presentCount++;
                } elseif ($item['status'] === 'Absent') {
                    $absentCount++;
                } elseif ($item['status'] === 'Rest Day') {
                    $restDayCount++;
                } elseif ($item['status'] === 'Deployed') {
                    $deployedCount++;
                } elseif ($item['status'] === 'Not Deployed') {
                    $notDeployedCount++;
                } elseif (strpos($item['status'], 'Borrowed in') === 0) {
                    $borrowedInOtherShiftCount++;
                } elseif (strpos($item['status'], 'Deployed in') === 0) {
                    $deployedInOtherShiftCount++;
                }
            }

            if ($item['status'] === 'Removed') {
                $removedCount++;
            }

            // Planned represents all home shift employees
            if ($item['home_shift_name'] === $item['shift_name']) {
                $plannedCount++;
            }
        }

        return [
            'planned' => $plannedCount,
            'present' => $presentCount,
            'leave' => $leaveCount,
            'borrowed' => $borrowedCount,
            'absent' => $absentCount,
            'rest_day' => $restDayCount,
            'deployed' => $deployedCount,
            'not_deployed' => $notDeployedCount,
            'removed' => $removedCount,
            'deployed_in_other_shift' => $deployedInOtherShiftCount,
            'borrowed_in_other_shift' => $borrowedInOtherShiftCount,
        ];
    }
}

