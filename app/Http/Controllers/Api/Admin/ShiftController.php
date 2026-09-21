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

        $dept->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Department deleted successfully'
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
