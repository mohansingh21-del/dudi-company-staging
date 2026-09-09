<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\ShiftEquipmentAllocation;
use App\Models\ShiftPlan;
use App\Models\BreakdownTicket;
use App\Models\ServiceRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EquipmentAllocationService
{
    /**
     * Service record statuses that keep a machine off the shop floor.
     * Completed and cancelled records release it again.
     *
     * @var array
     */
    protected $blockingServiceStatuses = ['pending', 'in_progress'];

    /**
     * 1) GET /machine-categories
     * Returns all rows from the `equipments` table.
     *
     * @return array
     */
    public function listCategories()
    {
        $categories = Equipment::where('is_active', 1)->get();

        $data = $categories->map(function ($category) {
            return [
                'category_id' => $category->id,
                'category_name' => $category->name,
            ];
        });

        return [
            'status' => 200,
            'message' => 'Machine categories retrieved successfully.',
            'data' => $data->values()->toArray(),
        ];
    }

    /**
     * 2) GET /shift-plans/{shift_plan_id}/equipment/available?category_id={id}
     * Returns active machines of a given category that are NOT currently
     * allocated to any non-closed shift.
     *
     * @param  int       $shiftPlanId
     * @param  int|null  $categoryId
     * @return array
     */
    public function getAvailableMachines($shiftPlanId, $categoryId)
    {
        // Validate category_id is present
        if (empty($categoryId)) {
            return [
                'status' => 422,
                'message' => 'Please Select Machine Type.',
                'data' => ['category_id' => ['Please Select Machine Type.']],
            ];
        }

        // Validate shift plan exists
        $shiftPlan = ShiftPlan::with('shift')->find($shiftPlanId);
        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan Not Found.',
                'data' => [],
            ];
        }

        // Validate category exists
        $category = Equipment::find($categoryId);
        if (!$category) {
            return [
                'status' => 404,
                'message' => 'Equipment Category Not Found.',
                'data' => [],
            ];
        }

        // Calculate target range
        $planningDate = Carbon::parse($shiftPlan->planning_date);
        $startTime = $shiftPlan->shift->start_time;
        $endTime = $shiftPlan->shift->end_time;

        $targetStart = Carbon::parse($planningDate->format('Y-m-d') . ' ' . $startTime);
        $targetEnd = Carbon::parse($planningDate->format('Y-m-d') . ' ' . $endTime);
        if (Carbon::parse($startTime)->greaterThanOrEqualTo(Carbon::parse($endTime))) {
            $targetEnd->addDay();
        }

        // Fetch all other non-closed shift plans
        $nonClosedPlans = ShiftPlan::with('shift')
            ->where('id', '!=', $shiftPlanId)
            ->notClosed()
            ->get();

        $overlappingPlanIds = [];
        foreach ($nonClosedPlans as $plan) {
            $planDate = Carbon::parse($plan->planning_date);
            $pStart = $plan->shift->start_time;
            $pEnd = $plan->shift->end_time;

            $planStart = Carbon::parse($planDate->format('Y-m-d') . ' ' . $pStart);
            $planEnd = Carbon::parse($planDate->format('Y-m-d') . ' ' . $pEnd);
            if (Carbon::parse($pStart)->greaterThanOrEqualTo(Carbon::parse($pEnd))) {
                $planEnd->addDay();
            }

            if ($targetStart->lessThan($planEnd) && $planStart->lessThan($targetEnd)) {
                $overlappingPlanIds[] = $plan->id;
            }
        }

        // IDs of machines already allocated to overlapping non-closed shifts
        $allocatedMachineIds = ShiftEquipmentAllocation::whereIn('shift_plan_id', $overlappingPlanIds)
            ->pluck('equipment_name_id')
            ->toArray();

        // IDs of machines currently in breakdown or maintenance (non-closed tickets)
        $breakdownMachineIds = BreakdownTicket::where('status', '!=', 'closed')
            ->pluck('equipment_name_id')
            ->toArray();

        // IDs of machines held by a service record that is still open
        $underServiceMachineIds = ServiceRecord::whereIn('status', $this->blockingServiceStatuses)
            ->pluck('machine_id')
            ->toArray();

        // Active machines of requested category, not already allocated, and not in
        // breakdown/maintenance or currently being serviced
        $machines = EquipmentName::with('equipment')
            ->where('equipment_id', $categoryId)
            ->where('is_active', 1)
            ->whereNotIn('id', $allocatedMachineIds)
            ->whereNotIn('id', $breakdownMachineIds)
            ->whereNotIn('id', $underServiceMachineIds)
            ->get();

        $data = $machines->map(function ($machine) {
            $categoryModel = $machine->equipment;
            return [
                'machine_id' => $machine->id,
                'machine_number' => $machine->equipment_name,
                'category_id' => $machine->equipment_id,
                'category_name' => $categoryModel ? $categoryModel->name : null,
                'status' => $machine->is_active ? 'Active' : 'Inactive',
            ];
        });

        return [
            'status' => 200,
            'message' => 'Available machines retrieved successfully.',
            'data' => $data->values()->toArray(),
        ];
    }

    /**
     * 3) POST /shift-plans/{shift_plan_id}/equipment
     * Allocate a machine to a shift, optionally nested under a parent category.
     *
     * @param  int    $shiftPlanId
     * @param  array  $data  ['machine_id' => int, 'parent_category_id' => int|null]
     * @return array
     */
    public function allocate($shiftPlanId, array $data)
    {
        // Validate shift plan exists
        $shiftPlan = ShiftPlan::with('shift')->find($shiftPlanId);
        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan Not Found.',
                'data' => [],
            ];
        }



        // Precondition: Shift exists in Draft Status
        if ($shiftPlan->status !== 'draft') {
            return [
                'status' => 422,
                'message' => 'Equipment can only be allocated to a shift in Draft status.',
                'data' => [],
            ];
        }

        $machineId = isset($data['machine_id']) ? $data['machine_id'] : null;
        $parentCategoryId = isset($data['parent_category_id']) ? $data['parent_category_id'] : null;

        // Validate machine_id is present
        if (empty($machineId)) {
            return [
                'status' => 422,
                'message' => 'Please Select Machine.',
                'data' => ['machine_id' => ['Please Select Machine.']],
            ];
        }

        // Validate machine exists and is active
        $machine = EquipmentName::with('equipment')->find($machineId);
        if (!$machine || !$machine->is_active) {
            return [
                'status' => 422,
                'message' => 'Machine Not Available For Allocation.',
                'data' => [
                    'machine_id' => ['Machine Not Available For Allocation.']
                ],
            ];
        }

        // Check if machine is in breakdown or maintenance (any non-closed breakdown ticket)
        $hasActiveBreakdown = BreakdownTicket::where('equipment_name_id', $machineId)
            ->where('status', '!=', 'closed')
            ->exists();

        if ($hasActiveBreakdown) {
            return [
                'status' => 422,
                'message' => 'Machine cannot be allocated as it is currently in breakdown or maintenance.',
                'data' => [
                    'machine_id' => ['Machine cannot be allocated as it is currently in breakdown or maintenance.']
                ],
            ];
        }

        // Check if machine is held by an open service record (pending / in progress)
        $openService = ServiceRecord::where('machine_id', $machineId)
            ->whereIn('status', $this->blockingServiceStatuses)
            ->first();

        if ($openService) {
            $message = "Machine cannot be allocated as it is currently under service (Ticket: {$openService->ticket_number}).";

            return [
                'status' => 422,
                'message' => $message,
                'data' => [
                    'machine_id' => [$message]
                ],
            ];
        }

        // Check if machine is already allocated to this specific shift plan
        $alreadyAllocatedHere = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlanId)
            ->where('equipment_name_id', $machineId)
            ->exists();

        if ($alreadyAllocatedHere) {
            return [
                'status' => 409,
                'message' => 'Machine Already Allocated To This Shift.',
                'data' => [],
            ];
        }

        // Calculate target range
        $planningDate = Carbon::parse($shiftPlan->planning_date);
        $startTime = $shiftPlan->shift->start_time;
        $endTime = $shiftPlan->shift->end_time;

        $targetStart = Carbon::parse($planningDate->format('Y-m-d') . ' ' . $startTime);
        $targetEnd = Carbon::parse($planningDate->format('Y-m-d') . ' ' . $endTime);
        if (Carbon::parse($startTime)->greaterThanOrEqualTo(Carbon::parse($endTime))) {
            $targetEnd->addDay();
        }

        // Fetch all other non-closed shift plans
        $nonClosedPlans = ShiftPlan::with('shift')
            ->where('id', '!=', $shiftPlanId)
            ->notClosed()
            ->get();

        $overlappingPlanIds = [];
        foreach ($nonClosedPlans as $plan) {
            $planDate = Carbon::parse($plan->planning_date);
            $pStart = $plan->shift->start_time;
            $pEnd = $plan->shift->end_time;

            $planStart = Carbon::parse($planDate->format('Y-m-d') . ' ' . $pStart);
            $planEnd = Carbon::parse($planDate->format('Y-m-d') . ' ' . $pEnd);
            if (Carbon::parse($pStart)->greaterThanOrEqualTo(Carbon::parse($pEnd))) {
                $planEnd->addDay();
            }

            if ($targetStart->lessThan($planEnd) && $planStart->lessThan($targetEnd)) {
                $overlappingPlanIds[] = $plan->id;
            }
        }

        $allocatedToOverlapping = ShiftEquipmentAllocation::whereIn('shift_plan_id', $overlappingPlanIds)
            ->where('equipment_name_id', $machineId)
            ->exists();

        if ($allocatedToOverlapping) {
            return [
                'status' => 409,
                'message' => 'Machine Already Allocated To Another Active Shift.',
                'data' => [],
            ];
        }

        // Validate parent_category_id (which represents the parent machine ID) if provided
        if ($parentCategoryId) {
            $parentMachine = EquipmentName::find($parentCategoryId);
            if (!$parentMachine) {
                return [
                    'status' => 404,
                    'message' => 'Parent Machine Not Found.',
                    'data' => [],
                ];
            }

            // Check if parent machine is allocated to this shift plan
            $isParentAllocated = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlanId)
                ->where('equipment_name_id', $parentCategoryId)
                ->exists();

            if (!$isParentAllocated) {
                return [
                    'status' => 422,
                    'message' => 'Parent Machine is not allocated to this shift.',
                    'data' => [],
                ];
            }
        }

        // Resolve category server-side from the machine's equipment_id
        $resolvedCategory = $machine->equipment;

        $allocation = DB::transaction(function () use ($shiftPlan, $machine, $parentCategoryId, $resolvedCategory) {
            $allocation = ShiftEquipmentAllocation::create([
                'shift_plan_id' => $shiftPlan->id,
                'equipment_name_id' => $machine->id,
                'parent_equipment_id' => $parentCategoryId,
                'allocated_by' => Auth::id(),
                'allocation_time' => Carbon::now(),
            ]);

            // Increment equipment count on the shift plan
            $shiftPlan->increment('equipment_count');

            return $allocation;
        });

        return [
            'status' => 201,
            'message' => 'Equipment Allocated Successfully.',
            'data' => [
                'allocation_id' => $allocation->id,
                'shift_id' => $shiftPlan->id,
                'machine_id' => $machine->id,
                'machine_number' => $machine->equipment_name,
                'category_id' => $resolvedCategory ? $resolvedCategory->id : null,
                'category_name' => $resolvedCategory ? $resolvedCategory->name : null,
                'parent_category_id' => $parentCategoryId,
                'allocation_time' => $allocation->allocation_time->toDateTimeString(),
                'allocated_by' => $allocation->allocated_by,
            ],
        ];
    }

    /**
     * 4) GET /shift-plans/{shift_plan_id}/equipment
     * Returns allocated equipment for a shift, structured with parent/child nesting.
     * Top-level machines (parent_equipment_id IS NULL) appear at root,
     * nested machines appear under their parent's "dumpers" array.
     *
     * @param  int  $shiftPlanId
     * @return array
     */
    public function listAllocated($shiftPlanId)
    {
        $shiftPlan = ShiftPlan::find($shiftPlanId);
        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan Not Found.',
                'data' => [],
            ];
        }

        $allocations = ShiftEquipmentAllocation::with(['equipmentName.equipment'])
            ->where('shift_plan_id', $shiftPlan->id)
            ->get();

        // Separate top-level (no parent) from nested (has parent_equipment_id)
        $topLevel = $allocations->whereNull('parent_equipment_id')->values();
        $nested = $allocations->whereNotNull('parent_equipment_id')->values();

        $data = $topLevel->map(function ($allocation) use ($nested) {
            $machine = $allocation->equipmentName;
            $category = $machine ? $machine->equipment : null;

            // Category ID of this top-level machine
            $thisCategoryId = $category ? $category->id : null;

            // Find nested machines allocated under this specific parent machine ID
            $childAllocations = $nested->filter(function ($child) use ($machine) {
                return $machine && $child->parent_equipment_id == $machine->id;
            })->values();

            $dumpers = $childAllocations->map(function ($child) {
                $childMachine = $child->equipmentName;
                return [
                    'allocation_id' => $child->id,
                    'machine_id' => $childMachine ? $childMachine->id : null,
                    'machine_number' => $childMachine ? $childMachine->equipment_name : null,
                ];
            });

            return [
                'allocation_id' => $allocation->id,
                'machine_id' => $machine ? $machine->id : null,
                'machine_number' => $machine ? $machine->equipment_name : null,
                'category_id' => $thisCategoryId,
                'category_name' => $category ? $category->name : null,
                'dumpers' => $dumpers->toArray(),
            ];
        });

        return [
            'status' => 200,
            'message' => 'Allocated equipment retrieved successfully.',
            'data' => $data->values()->toArray(),
        ];
    }

    /**
     * 5) DELETE /shift-plans/{shift_plan_id}/equipment/{allocation_id}
     * Removes the allocation row. If the removed machine's category has
     * nested machines allocated under it (same shift), those are cascade-removed too.
     *
     * @param  int  $shiftPlanId
     * @param  int  $allocationId
     * @return array
     */
    public function remove($shiftPlanId, $allocationId)
    {
        $shiftPlan = ShiftPlan::with('shift')->find($shiftPlanId);
        if (!$shiftPlan) {
            return [
                'status' => 404,
                'message' => 'Shift Plan Not Found.',
                'data' => [],
            ];
        }



        // Precondition: Shift exists in Draft Status
        if ($shiftPlan->status !== 'draft') {
            return [
                'status' => 422,
                'message' => 'Equipment allocation can only be removed from a shift in Draft status.',
                'data' => [],
            ];
        }

        $allocation = ShiftEquipmentAllocation::where('id', $allocationId)
            ->where('shift_plan_id', $shiftPlan->id)
            ->first();

        if (!$allocation) {
            return [
                'status' => 404,
                'message' => 'Equipment Allocation Not Found.',
                'data' => [],
            ];
        }

        DB::transaction(function () use ($allocation, $shiftPlan) {
            $removedCount = 1; // the allocation itself

            // If this is a top-level allocation, check for nested children
            $machine = $allocation->equipmentName;
            $isTopLevel = is_null($allocation->parent_equipment_id);

            if ($isTopLevel && $machine) {
                // Delete nested allocations under this machine ID within the same shift
                $childQuery = ShiftEquipmentAllocation::where('shift_plan_id', $shiftPlan->id)
                    ->where('parent_equipment_id', $machine->id);

                $childCount = $childQuery->count();
                $childQuery->delete();

                $removedCount += $childCount;
            }

            // Delete the allocation itself
            $allocation->delete();

            // Decrement equipment count
            $shiftPlan->decrement('equipment_count', $removedCount);

            // Ensure equipment_count doesn't go below 0
            if ($shiftPlan->equipment_count < 0) {
                $shiftPlan->update(['equipment_count' => 0]);
            }
        });

        return [
            'status' => 200,
            'message' => 'Machine Allocation Removed Successfully.',
            'data' => [],
        ];
    }

    /**
     * GET /shift-plans/{shift_id}/machines
     * Returns allocated equipment for a shift (resolved via shift_id).
     *
     * @param  int         $shiftId
     * @param  string|null $date
     * @return array
     */
    public function listAllocatedByShift($shiftId, $date = null)
    {
        // 1. Verify that the shift itself exists
        $shiftExists = \App\Models\Shift::where('id', $shiftId)->exists();
        if (!$shiftExists) {
            return [
                'status' => 404,
                'message' => 'Shift Not Found.',
                'data' => [],
            ];
        }

        // 2. Resolve date: query parameter, argument, or today
        if ($date) {
            $targetDate = Carbon::parse($date)->format('Y-m-d');
        } else {
            $targetDate = Carbon::today()->format('Y-m-d');
        }

        // 3. Find shift plans for this shift on the target date
        $shiftPlans = ShiftPlan::where('shift_id', $shiftId)
            ->whereDate('planning_date', $targetDate)
            ->get();

        // 4. Fallback: if no shift plans exist for the target date (and no custom date was passed),
        // fallback to the latest date that has any shift plan for this shift
        if ($shiftPlans->isEmpty() && !$date) {
            $latestDate = ShiftPlan::where('shift_id', $shiftId)
                ->latest('planning_date')
                ->value('planning_date');

            if ($latestDate) {
                $targetDate = Carbon::parse($latestDate)->format('Y-m-d');
                $shiftPlans = ShiftPlan::where('shift_id', $shiftId)
                    ->whereDate('planning_date', $targetDate)
                    ->get();
            }
        }

        if ($shiftPlans->isEmpty()) {
            return [
                'status' => 200,
                'message' => 'Allocated equipment retrieved successfully.',
                'data' => [],
            ];
        }

        $shiftPlanIds = $shiftPlans->pluck('id')->toArray();

        // 5. Fetch all allocations for these shift plans
        $allocations = ShiftEquipmentAllocation::with(['equipmentName.equipment'])
            ->whereIn('shift_plan_id', $shiftPlanIds)
            ->get();

        $data = $allocations->map(function ($allocation) {
            $machine = $allocation->equipmentName;
            $category = $machine ? $machine->equipment : null;

            return [
                'allocation_id' => $allocation->id,
                'machine_id' => $machine ? $machine->id : null,
                'machine_number' => $machine ? $machine->equipment_name : null,
                'category_id' => $category ? $category->id : null,
                'category_name' => $category ? $category->name : null,
            ];
        });

        return [
            'status' => 200,
            'message' => 'Allocated equipment retrieved successfully.',
            'data' => $data->values()->toArray(),
        ];
    }
}

