<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Shift;
use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Models\ShiftPlan;
use App\Http\Resources\ShiftPlanResource;
use App\Models\ShiftEquipmentAllocation;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $departments = Shift::query();

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $departments->where(function ($query) use ($search) {
                    $query->where('shift_name', 'LIKE', "%{$search}%");
                    //->orWhere('address', 'LIKE', "%{$search}%");
                });
            }

            $departments = $departments
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Shift list fetched successfully',
                'data' => ShiftResource::collection($departments),
                'pagination' => [
                    'current_page' => $departments->currentPage(),
                    'last_page' => $departments->lastPage(),
                    'per_page' => $departments->perPage(),
                    'total' => $departments->total(),
                    'from' => $departments->firstItem(),
                    'to' => $departments->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function store(StoreShiftRequest $request)
    {
        $conflict = $this->findOverlappingShift($request->start_time, $request->end_time);

        if ($conflict) {
            return response()->json([
                'status' => 422,
                'message' => 'Shift timings overlap with an existing shift.',
                'conflicting_shift' => [
                    'id' => $conflict->id,
                    'shift_name' => $conflict->shift_name,
                    'start_time' => $conflict->start_time,
                    'end_time' => $conflict->end_time,
                ]
            ], 422);
        }

        $dept = Shift::create([
            'shift_name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'minimum_working_hours' => $request->minimum_working_hours,
            'is_night_shift' => $request->is_night_shift,
        ]);

        $dept->is_active = 1;
        $dept->save();

        return response()->json([
            'status' => 200,
            'message' => 'Shift created'
            // 'data' => new DepartmentResource($dept)
        ]);
    }

    public function show(int $id)
    {
        $dept = Shift::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Shift not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new ShiftResource($dept)
        ]);
    }
    public function update(UpdateShiftRequest $request, int $id)
    {
        $dept = Shift::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Site not found'
            ]);
        }

        $conflict = $this->findOverlappingShift($request->start_time, $request->end_time, $id);

        if ($conflict) {
            return response()->json([
                'status' => 422,
                'message' => 'Shift timings overlap with an existing shift.',
                'conflicting_shift' => [
                    'id' => $conflict->id,
                    'shift_name' => $conflict->shift_name,
                    'start_time' => $conflict->start_time,
                    'end_time' => $conflict->end_time,
                ]
            ], 422);
        }

        $dept->update([
            'shift_name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'minimum_working_hours' => $request->minimum_working_hours,
            'is_night_shift' => $request->is_night_shift
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Shift updated successfully'
        ]);
    }

    public function destroy(int $id)
    {
        $dept = Shift::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Department not found'
            ]);
        }

        // Deleting cascades into assignments, overrides and relay mappings, so an
        // in-use shift would silently wipe those employees' rosters.
        $blocked = $this->blockedByDependencies($dept, 'delete');

        if ($blocked) {
            return $blocked;
        }

        try {
            $dept->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            // Shift plans, breakdowns, delays, fuel entries and dispatch trips keep
            // their shift with ON DELETE RESTRICT, so historical records block this.
            if (($e->errorInfo[1] ?? null) === 1451) {
                return response()->json([
                    'status' => 422,
                    'message' => "Cannot delete shift '{$dept->shift_name}' because it is referenced by existing records (shift plans, breakdowns, delays, fuel entries or dispatch trips). Deactivate the shift instead."
                ], 422);
            }

            throw $e;
        }

        return response()->json([
            'status' => 200,
            'message' => 'Shift deleted successfully'
        ]);
    }
    public function toggleStatus(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $dept = Shift::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Shift not found'
            ]);
        }

        $activate = (int) $request->status === 1;

        // Deactivating a shift that people are still rostered on would leave them
        // pointing at a shift the rest of the app no longer offers, so the admin
        // has to move them to another shift first.
        if (!$activate && $dept->is_active) {
            $blocked = $this->blockedByDependencies($dept, 'deactivate');

            if ($blocked) {
                return $blocked;
            }
        }

        // Re-activating a shift re-occupies its time slot, so the slot must be
        // re-validated here: while this shift was inactive another shift may
        // have been created over the same timings.
        if ($activate && !$dept->is_active) {
            $conflict = $this->findOverlappingShift($dept->start_time, $dept->end_time, $dept->id);

            if ($conflict) {
                return response()->json([
                    'status' => 422,
                    'message' => "Cannot activate this shift: its timings overlap with the active shift '{$conflict->shift_name}' ({$conflict->start_time} - {$conflict->end_time}). Deactivate or re-time that shift first.",
                    'conflicting_shift' => [
                        'id' => $conflict->id,
                        'shift_name' => $conflict->shift_name,
                        'start_time' => $conflict->start_time,
                        'end_time' => $conflict->end_time,
                    ]
                ], 422);
            }
        }

        $dept->is_active = $activate ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'Shift status updated successfully'
        ]);
    }

    public function getPublicShifts(Request $request)
    {
        try {

            $limit = $request->input('limit', null);

            $departments = Shift::where('is_active', 1)->select('id', 'shift_name', 'is_active');

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $departments->where(function ($query) use ($search) {
                    $query->where('shift_name', 'LIKE', "%{$search}%");
                    //->orWhere('address', 'LIKE', "%{$search}%");
                });
            }

            $departments = $departments
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Shift list fetched successfully',
                'data' => $departments->items(),
                'pagination' => [
                    'current_page' => $departments->currentPage(),
                    'last_page' => $departments->lastPage(),
                    'per_page' => $departments->perPage(),
                    'total' => $departments->total(),
                    'from' => $departments->firstItem(),
                    'to' => $departments->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function findShiftByDateTime(Request $request)
    {
        try {
            $request->validate([
                'date' => 'nullable',
                'time' => 'nullable',
                'datetime' => 'nullable',
                'date_time' => 'nullable',
            ]);

            $targetDate = $request->query('date');
            $targetTime = $request->query('time');
            $dateTimeStr = $request->query('datetime') ?? $request->query('date_time');

            $timeProvided = false;

            if ($targetTime !== null && $targetTime !== '') {
                $timeProvided = true;
            }

            if ($dateTimeStr) {
                $trimmed = trim($dateTimeStr);
                if (strlen($trimmed) > 10 || str_contains($trimmed, ':') || str_contains($trimmed, ' ')) {
                    $timeProvided = true;
                }
            }

            if ($timeProvided) {
                if ($dateTimeStr) {
                    try {
                        $dt = \Carbon\Carbon::parse($dateTimeStr);
                        $targetDate = $dt->format('Y-m-d');
                        $targetTime = $dt->format('H:i:s');
                    } catch (\Throwable $e) {
                        return response()->json([
                            'status' => 422,
                            'message' => 'Invalid datetime format.'
                        ], 422);
                    }
                }

                if (!$targetDate) {
                    $targetDate = \Carbon\Carbon::today()->format('Y-m-d');
                }

                if (!$targetTime) {
                    $targetTime = \Carbon\Carbon::now()->format('H:i:s');
                } else {
                    try {
                        $targetTime = \Carbon\Carbon::parse($targetTime)->format('H:i:s');
                    } catch (\Throwable $e) {
                        return response()->json([
                            'status' => 422,
                            'message' => 'Invalid time format.'
                        ], 422);
                    }
                }

                $matchedShift = null;
                $shifts = Shift::where('is_active', 1)->get();

                foreach ($shifts as $shift) {
                    $start = $shift->start_time;
                    $end = $shift->end_time;

                    if ($start > $end) {
                        if ($targetTime >= $start || $targetTime < $end) {
                            $matchedShift = $shift;
                            break;
                        }
                    } else {
                        if ($targetTime >= $start && $targetTime < $end) {
                            $matchedShift = $shift;
                            break;
                        }
                    }
                }

                if (!$matchedShift) {
                    return response()->json([
                        'status' => 404,
                        'message' => 'No active shift covers the given time.',
                        'data' => null
                    ], 404);
                }

                $planningDate = $targetDate;
                if ($matchedShift->start_time > $matchedShift->end_time) {
                    if ($targetTime < $matchedShift->end_time) {
                        $planningDate = \Carbon\Carbon::parse($targetDate)->subDay()->format('Y-m-d');
                    }
                }

                // Closed / completed plans must still be resolvable: the shift that
                // covers a given datetime does not stop existing once it is closed.
                // Open plans win when both exist for the same shift + date.
                $shiftPlan = ShiftPlan::with('site')->where('shift_id', $matchedShift->id)
                    ->whereDate('planning_date', $planningDate)
                    ->whereIn('status', ['published', 'in_progress', 'active', 'planned', 'completed', 'closed'])
                    ->orderByRaw("CASE WHEN status IN ('completed', 'closed') THEN 1 ELSE 0 END")
                    ->first();

                if (!$shiftPlan) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'No shift plan found for the selected date.',
                        'data' => null
                    ], 422);
                }

                $machines = [];
                $allocations = ShiftEquipmentAllocation::with(['equipmentName.equipment'])
                    ->where('shift_plan_id', $shiftPlan->id)
                    ->get();

                foreach ($allocations as $allocation) {
                    $machine = $allocation->equipmentName;
                    $category = $machine ? $machine->equipment : null;
                    if ($machine) {
                        $breakdown = \App\Models\BreakdownTicket::where('equipment_allocation_id', $allocation->id)
                            ->where('status', '!=', 'closed')
                            ->first();

                        if (!$breakdown) {
                            $breakdown = \App\Models\BreakdownTicket::where('equipment_name_id', $machine->id)
                                ->where('shift_id', $shiftPlan->shift_id)
                                ->where('status', '!=', 'closed')
                                ->first();
                        }

                        $breakdownData = null;
                        if ($breakdown) {
                            $breakdownData = [
                                'id' => $breakdown->id,
                                'ticket_number' => $breakdown->ticket_number,
                                'status' => $breakdown->status,
                                'severity' => $breakdown->severity,
                                'description' => $breakdown->description,
                                'breakdown_date_time' => $breakdown->breakdown_date_time ? $breakdown->breakdown_date_time->toDateTimeString() : null,
                            ];
                        }

                        $machines[] = [
                            'machine_id' => $machine->id,
                            'machine_name' => $machine->equipment_name,
                            'category_id' => $category ? $category->id : null,
                            'category_name' => $category ? $category->name : null,
                            'parent_machine_id' => $allocation->parent_equipment_id,
                            'breakdown' => $breakdownData,
                        ];
                    }
                }

                $siteData = null;
                if ($shiftPlan->site) {
                    $siteData = [
                        'id' => $shiftPlan->site->id,
                        'site_name' => $shiftPlan->site->site_name,
                        'address' => $shiftPlan->site->address,
                    ];
                }

                $drivers = [];
                $workforce = [];
                $deployments = \App\Models\ShiftWorkforceDeployment::with(['employee.designation'])
                    ->where('shift_plan_id', $shiftPlan->id)
                    ->where('status', 'active')
                    ->get();

                foreach ($deployments as $deployment) {
                    $emp = $deployment->employee;
                    $designationName = $deployment->designation ?? ($emp && $emp->designation ? $emp->designation->name : null);
                    $slug = $emp && $emp->designation ? $emp->designation->slug : null;

                    $item = [
                        'id' => $emp ? $emp->id : null,
                        'employee_code' => $emp ? $emp->employee_code : null,
                        'name' => $emp ? $emp->name : null,
                        'mobile' => $emp ? $emp->mobile : null,
                        'designation' => $designationName,
                    ];

                    if ($deployment->designation === 'Driver' || $deployment->designation === 'driver' || $slug === 'driver') {
                        $drivers[] = $item;
                    } else {
                        $workforce[] = $item;
                    }
                }

                return response()->json([
                    'status' => 200,
                    'message' => 'Shift details retrieved successfully.',
                    'data' => [
                        'id' => $matchedShift->id,
                        'name' => $matchedShift->shift_name,
                        'start_time' => $matchedShift->start_time,
                        'end_time' => $matchedShift->end_time,
                        'shift_plan_id' => $shiftPlan ? $shiftPlan->id : null,
                        'shift_plan_status' => $shiftPlan ? $shiftPlan->status : null,
                        'is_closed' => $shiftPlan ? in_array($shiftPlan->status, ['completed', 'closed']) : false,
                        'site' => $siteData,
                        'drivers' => $drivers,
                        'workforce' => $workforce,
                        'machines' => $machines,
                    ]
                ], 200);

            } else {
                if ($dateTimeStr) {
                    try {
                        $dt = \Carbon\Carbon::parse($dateTimeStr);
                        $targetDate = $dt->format('Y-m-d');
                    } catch (\Throwable $e) {
                        return response()->json([
                            'status' => 422,
                            'message' => 'Invalid datetime format.'
                        ], 422);
                    }
                }

                if (!$targetDate) {
                    $targetDate = \Carbon\Carbon::today()->format('Y-m-d');
                }

                $shiftPlans = ShiftPlan::with(['shift', 'site', 'equipmentAllocations.equipmentName.equipment'])
                    ->whereDate('planning_date', $targetDate)
                    ->whereIn('status', ['published', 'in_progress', 'active', 'planned', 'completed', 'closed'])
                    ->get();

                $data = [];
                foreach ($shiftPlans as $shiftPlan) {
                    $matchedShift = $shiftPlan->shift;
                    if (!$matchedShift) {
                        continue;
                    }

                    $machines = [];
                    foreach ($shiftPlan->equipmentAllocations as $allocation) {
                        $machine = $allocation->equipmentName;
                        $category = $machine ? $machine->equipment : null;
                        if ($machine) {
                            $breakdown = \App\Models\BreakdownTicket::where('equipment_allocation_id', $allocation->id)
                                ->where('status', '!=', 'closed')
                                ->first();

                            if (!$breakdown) {
                                $breakdown = \App\Models\BreakdownTicket::where('equipment_name_id', $machine->id)
                                    ->where('shift_id', $shiftPlan->shift_id)
                                    ->where('status', '!=', 'closed')
                                    ->first();
                            }

                            $breakdownData = null;
                            if ($breakdown) {
                                $breakdownData = [
                                    'id' => $breakdown->id,
                                    'ticket_number' => $breakdown->ticket_number,
                                    'status' => $breakdown->status,
                                    'severity' => $breakdown->severity,
                                    'description' => $breakdown->description,
                                    'breakdown_date_time' => $breakdown->breakdown_date_time ? $breakdown->breakdown_date_time->toDateTimeString() : null,
                                ];
                            }

                            $machines[] = [
                                'machine_id' => $machine->id,
                                'machine_name' => $machine->equipment_name,
                                'category_id' => $category ? $category->id : null,
                                'category_name' => $category ? $category->name : null,
                                'parent_machine_id' => $allocation->parent_equipment_id,
                                'breakdown' => $breakdownData,
                            ];
                        }
                    }

                    $siteData = null;
                    if ($shiftPlan->site) {
                        $siteData = [
                            'id' => $shiftPlan->site->id,
                            'site_name' => $shiftPlan->site->site_name,
                            'address' => $shiftPlan->site->address,
                        ];
                    }

                    $drivers = [];
                    $workforce = [];
                    $deployments = \App\Models\ShiftWorkforceDeployment::with(['employee.designation'])
                        ->where('shift_plan_id', $shiftPlan->id)
                        ->where('status', 'active')
                        ->get();

                    foreach ($deployments as $deployment) {
                        $emp = $deployment->employee;
                        $designationName = $deployment->designation ?? ($emp && $emp->designation ? $emp->designation->name : null);
                        $slug = $emp && $emp->designation ? $emp->designation->slug : null;

                        $item = [
                            'id' => $emp ? $emp->id : null,
                            'employee_code' => $emp ? $emp->employee_code : null,
                            'name' => $emp ? $emp->name : null,
                            'mobile' => $emp ? $emp->mobile : null,
                            'designation' => $designationName,
                        ];

                        if ($deployment->designation === 'Driver' || $deployment->designation === 'driver' || $slug === 'driver') {
                            $drivers[] = $item;
                        } else {
                            $workforce[] = $item;
                        }
                    }

                    $data[] = [
                        'id' => $matchedShift->id,
                        'name' => $matchedShift->shift_name,
                        'start_time' => $matchedShift->start_time,
                        'end_time' => $matchedShift->end_time,
                        'shift_plan_id' => $shiftPlan->id,
                        'shift_plan_status' => $shiftPlan->status,
                        'is_closed' => in_array($shiftPlan->status, ['completed', 'closed']),
                        'site' => $siteData,
                        'drivers' => $drivers,
                        'workforce' => $workforce,
                        'machines' => $machines,
                    ];
                }

                return response()->json([
                    'status' => 200,
                    'message' => 'Shift details retrieved successfully.',
                    'data' => $data
                ], 200);
            }

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * A 422 response listing what still uses the shift, or null when it is free.
     */
    private function blockedByDependencies(Shift $shift, string $action)
    {
        $dependencies = $this->getActiveShiftDependencies($shift);

        $labels = [
            'employees' => 'active employee(s) rostered on it',
            'relays' => 'relay(s) mapped to it for the current or upcoming weeks',
            'shift_plans' => 'upcoming or running shift plan(s)',
            'open_attendance' => 'open attendance record(s) not yet checked out',
        ];

        $parts = [];
        foreach ($labels as $key => $label) {
            if ($dependencies[$key] > 0) {
                $parts[] = "{$dependencies[$key]} {$label}";
            }
        }

        if (empty($parts)) {
            return null;
        }

        return response()->json([
            'status' => 422,
            'message' => "Cannot {$action} shift '{$shift->shift_name}': it has " . implode(', ', $parts) . '. Reassign them to another shift first.',
            'dependencies' => $dependencies,
        ], 422);
    }

    /**
     * Count what is still using a shift today or later.
     */
    private function getActiveShiftDependencies(Shift $shift): array
    {
        $today = \Carbon\Carbon::today();
        $todayStr = $today->toDateString();

        // Employees rostered on this shift today, resolved with the same
        // precedence as attendance and payroll (override > relay > assignment).
        $employees = \App\Models\Employee::with('relay')->where('is_active', 1)->get();
        $resolver = app(\App\Services\ShiftRosterResolver::class);
        $ctx = $resolver->preload($employees, $today, $today);

        $employeeIds = $employees
            ->filter(function ($employee) use ($resolver, $todayStr, $ctx, $shift) {
                return (int) $resolver->resolve($employee, $todayStr, $ctx) === (int) $shift->id;
            })
            ->pluck('id');

        // Plus employees moving onto this shift on a later date.
        $activeEmployeeIds = $employees->pluck('id');

        $futureOverrideIds = \App\Models\EmployeeShiftOverride::where('shift_id', $shift->id)
            ->whereIn('employee_id', $activeEmployeeIds)
            ->whereDate('effective_from', '>', $todayStr)
            ->pluck('employee_id');

        $futureAssignmentIds = \App\Models\EmployeeShiftAssignment::where('shift_id', $shift->id)
            ->whereIn('employee_id', $activeEmployeeIds)
            ->whereDate('from_date', '>', $todayStr)
            ->pluck('employee_id');

        $employeeCount = $employeeIds
            ->merge($futureOverrideIds)
            ->merge($futureAssignmentIds)
            ->unique()
            ->count();

        // Active relays holding this shift this week or later. A relay with no
        // mapping for this week falls back to its latest one (see
        // RelayShiftMapping::getLatestForRelay), so that counts as current too.
        $relayCount = 0;
        foreach (\App\Models\Relay::where('is_active', 1)->pluck('id') as $relayId) {
            $current = \App\Models\RelayShiftMapping::getForDate($relayId, $todayStr)
                ?? \App\Models\RelayShiftMapping::getLatestForRelay($relayId);

            $holdsShift = ($current && (int) $current->shift_id === (int) $shift->id)
                || \App\Models\RelayShiftMapping::where('relay_id', $relayId)
                    ->where('shift_id', $shift->id)
                    ->whereDate('week_start_date', '>', $todayStr)
                    ->exists();

            if ($holdsShift) {
                $relayCount++;
            }
        }

        // Plans not yet closed that are dated today or later, or still running.
        $planCount = ShiftPlan::where('shift_id', $shift->id)
            ->notClosed()
            ->where(function ($q) use ($todayStr) {
                $q->whereDate('planning_date', '>=', $todayStr)
                    ->orWhere('status', 'in_progress');
            })
            ->count();

        // Checked in but not out; yesterday included for night shifts past midnight.
        $openAttendanceCount = \App\Models\AttendanceProcessed::where('shift_id', $shift->id)
            ->whereDate('date', '>=', $today->copy()->subDay()->toDateString())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->count();

        return [
            'employees' => $employeeCount,
            'relays' => $relayCount,
            'shift_plans' => $planCount,
            'open_attendance' => $openAttendanceCount,
        ];
    }

    private function isShiftOverlapping($startTime, $endTime, $excludeId = null)
    {
        return $this->findOverlappingShift($startTime, $endTime, $excludeId) !== null;
    }

    /**
     * Return the first active shift whose timings overlap the given window,
     * or null when the window is free. Inactive shifts do not hold a slot.
     */
    private function findOverlappingShift($startTime, $endTime, $excludeId = null)
    {
        $newStart = \Carbon\Carbon::parse($startTime)->format('H:i:s');
        $newEnd = \Carbon\Carbon::parse($endTime)->format('H:i:s');

        $getIntervals = function ($start, $end) {
            $intervals = [];
            if ($start < $end) {
                $intervals[] = ['start' => $start, 'end' => $end];
            } elseif ($start > $end) {
                $intervals[] = ['start' => $start, 'end' => '24:00:00'];
                if ($end !== '00:00:00') {
                    $intervals[] = ['start' => '00:00:00', 'end' => $end];
                }
            } else {
                $intervals[] = ['start' => '00:00:00', 'end' => '24:00:00'];
            }
            return $intervals;
        };

        $newIntervals = $getIntervals($newStart, $newEnd);

        $query = Shift::where('is_active', 1);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $existingShifts = $query->get();

        foreach ($existingShifts as $existingShift) {
            $existStart = \Carbon\Carbon::parse($existingShift->start_time)->format('H:i:s');
            $existEnd = \Carbon\Carbon::parse($existingShift->end_time)->format('H:i:s');

            $existingIntervals = $getIntervals($existStart, $existEnd);

            foreach ($newIntervals as $newInt) {
                foreach ($existingIntervals as $existInt) {
                    $maxStart = max($newInt['start'], $existInt['start']);
                    $minEnd = min($newInt['end'], $existInt['end']);

                    if ($maxStart < $minEnd) {
                        return $existingShift;
                    }
                }
            }
        }

        return null;
    }
}
