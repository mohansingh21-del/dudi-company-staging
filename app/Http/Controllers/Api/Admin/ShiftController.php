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
        if ($this->isShiftOverlapping($request->start_time, $request->end_time)) {
            return response()->json([
                'status' => 422,
                'message' => 'Shift start time cannot be same as an existing shift.'
            ], 422);
        }

        $dept = Shift::create([
            'shift_name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'minimum_working_hours' => $request->minimum_working_hours,
            'is_night_shift' => $request->is_night_shift,
            'status' => 1
        ]);

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

        if ($this->isShiftOverlapping($request->start_time, $request->end_time, $id)) {
            return response()->json([
                'status' => 422,
                'message' => 'Shift start time cannot be same as an existing shift.'
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
        $dept = Shift::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Shift not found'
            ]);
        }
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $dept->is_active = $request->status ? 1 : 0;
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

                $shiftPlan = ShiftPlan::where('shift_id', $matchedShift->id)
                    ->whereDate('planning_date', $planningDate)
                    ->whereIn('status', ['published', 'in_progress', 'active', 'planned'])
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
                        $machines[] = [
                            'machine_id' => $machine->id,
                            'machine_name' => $machine->equipment_name,
                            'category_id' => $category ? $category->id : null,
                            'category_name' => $category ? $category->name : null,
                        ];
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

                $shiftPlans = ShiftPlan::with(['shift', 'equipmentAllocations.equipmentName.equipment'])
                    ->whereDate('planning_date', $targetDate)
                    ->whereIn('status', ['published', 'in_progress', 'active', 'planned'])
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
                            $machines[] = [
                                'machine_id' => $machine->id,
                                'machine_name' => $machine->equipment_name,
                                'category_id' => $category ? $category->id : null,
                                'category_name' => $category ? $category->name : null,
                            ];
                        }
                    }

                    $data[] = [
                        'id' => $matchedShift->id,
                        'name' => $matchedShift->shift_name,
                        'start_time' => $matchedShift->start_time,
                        'end_time' => $matchedShift->end_time,
                        'shift_plan_id' => $shiftPlan->id,
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
        $formattedStartTime = \Carbon\Carbon::parse($startTime)->format('H:i:00');

        $query = Shift::where('is_active', 1)
            ->where('start_time', $formattedStartTime);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}
