<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeShiftHistoryResource;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftHistory;
use App\Models\EmployeeShiftOverride;
use App\Models\Employee;
use App\Models\ShiftWorkforceDeployment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ShiftChangeController extends Controller
{
    /**
     * Display a listing of shift change histories.
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);
            $today = now()->toDateString();
            $weekStart = Carbon::parse($today)->startOfWeek(Carbon::MONDAY)->toDateString();

            // Resolve each rotating relay's effective shift the same way Relay::getCurrentShiftIdAttribute()
            // does: current week's mapping if present, else fall back to the latest mapping on record.
            // An exact "week_start_date = this week's Monday" match would go empty whenever the weekly
            // rotation job hasn't run yet, so it can't be pushed down as a plain whereHas() condition.
            $relayCurrentShiftIds = \App\Models\Relay::where('is_active', 1)
                ->where('is_rotating', 1)
                ->get()
                ->mapWithKeys(fn ($relay) => [$relay->id => $relay->current_shift_id]);

            $resolvableRelayIds = $relayCurrentShiftIds->filter(fn ($shiftId) => $shiftId !== null)->keys()->toArray();

            $query = Employee::where('is_active', 1)
                ->whereHas('relay', function ($q) {
                    $q->where('is_rotating', true);
                })
                ->where(function ($q) use ($resolvableRelayIds) {
                    $q->whereHas('currentShiftAssignment')
                      ->orWhereIn('relay_id', $resolvableRelayIds);
                })
                ->with(['currentShiftAssignment.shift', 'department', 'site', 'designation', 'supervisor', 'relay']);

            if ($request->filled('shift_id')) {
                $requestedShiftId = $request->shift_id;
                $matchingRelayIds = $relayCurrentShiftIds->filter(fn ($shiftId) => $shiftId == $requestedShiftId)->keys()->toArray();
                $query->where(function ($q) use ($requestedShiftId, $matchingRelayIds) {
                    $q->whereHas('currentShiftAssignment', function ($sub) use ($requestedShiftId) {
                        $sub->where('shift_id', $requestedShiftId);
                    })
                    ->orWhereIn('relay_id', $matchingRelayIds);
                });
            }

            if ($request->filled('from_date')) {
                $fromDate = $request->from_date;
                $query->where(function ($q) use ($fromDate) {
                    $q->whereHas('currentShiftAssignment', function ($sub) use ($fromDate) {
                        $sub->where(function ($sub2) use ($fromDate) {
                            $sub2->whereNotNull('from_date')
                                 ->where('from_date', '>=', $fromDate)
                                 ->orWhere(function ($sub3) use ($fromDate) {
                                     $sub3->whereNull('from_date')
                                          ->whereDate('created_at', '>=', $fromDate);
                                 });
                        });
                    })
                    ->orWhere(function ($sub) use ($fromDate) {
                        $sub->whereDoesntHave('currentShiftAssignment')
                            ->whereHas('relay.shiftMappings', function ($m) use ($fromDate) {
                                $m->where('week_start_date', '>=', $fromDate);
                            });
                    });
                });
            }

            if ($request->filled('to_date')) {
                $toDate = $request->to_date;
                $query->where(function ($q) use ($toDate) {
                    $q->whereHas('currentShiftAssignment', function ($sub) use ($toDate) {
                        $sub->where(function ($sub2) use ($toDate) {
                            $sub2->whereNotNull('from_date')
                                 ->where('from_date', '<=', $toDate)
                                 ->orWhere(function ($sub3) use ($toDate) {
                                     $sub3->whereNull('from_date')
                                          ->whereDate('created_at', '<=', $toDate);
                                 });
                        });
                    })
                    ->orWhere(function ($sub) use ($toDate) {
                        $sub->whereDoesntHave('currentShiftAssignment')
                            ->whereHas('relay.shiftMappings', function ($m) use ($toDate) {
                                $m->where('week_start_date', '<=', $toDate);
                            });
                    });
                });
            }

            if ($request->filled('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            if ($request->filled('site_id')) {
                $query->where('site_id', $request->site_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('employee_code', 'LIKE', "%{$search}%");
                });
            }

            $employees = $query->latest()->paginate($limit);

            $employees->through(function ($employee) {
                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'site' => optional($employee->site)->site_name,
                    'department' => optional($employee->department)->name,
                    'shift' => $employee->shift_id ? optional(\App\Models\Shift::find($employee->shift_id))->shift_name : null,
                    'relay' => optional($employee->relay)->name,
                    'relay_shift' => optional($employee->relay)->name,
                    'relay_id' => $employee->relay_id,
                ];
            });

            return response()->json([
                'status' => 200,
                'message' => 'Shift change data retrieved successfully',
                'data' => $employees->items(),
                'pagination' => [
                    'current_page' => $employees->currentPage(),
                    'last_page' => $employees->lastPage(),
                    'per_page' => $employees->perPage(),
                    'total' => $employees->total(),
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    /**
     * Manually change shifts of employees.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'employee_id' => 'required_without:employee_ids',
                'employee_ids' => 'required_without:employee_id|array',
                'target_shift_id' => 'required_without:shift_id|exists:shifts,id',
                'shift_id' => 'required_without:target_shift_id|exists:shifts,id',
                'current_shift_id' => 'nullable|exists:shifts,id',
            ]);

            $targetShiftId = $request->input('target_shift_id', $request->shift_id);
            $identifiers = $request->has('employee_ids')
                ? $request->employee_ids
                : [$request->employee_id];

            $identifiers = array_filter($identifiers, function ($value) {
                return !is_null($value) && trim((string) $value) !== '';
            });

            if (empty($identifiers)) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Please select at least one employee.'
                ], 422);
            }

            $resolvedEmployees = collect();
            foreach ($identifiers as $identifier) {
                $employee = Employee::with('relay')
                    ->where('id', $identifier)
                    ->orWhere('employee_code', $identifier)
                    ->first();

                if (!$employee) {
                    return response()->json([
                        'status' => 422,
                        'message' => "Employee '{$identifier}' not found."
                    ], 422);
                }

                if (!$employee->relay_id || !$employee->relay || !$employee->relay->is_rotating) {
                    continue;
                }

                $resolvedEmployees->push($employee);
            }

            if ($resolvedEmployees->isEmpty()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'No active relay employees selected for shift rotation.'
                ], 422);
            }

            if ($blockedResponse = $this->openDeploymentResponse($resolvedEmployees)) {
                return $blockedResponse;
            }

            $resolvedEmployeeIds = $resolvedEmployees->pluck('id')->all();

            $newRelayId = $this->resolveRelayIdForShift($targetShiftId);

            if (!$newRelayId) {
                return $this->unmappedShiftResponse($targetShiftId);
            }

            DB::transaction(function () use ($resolvedEmployeeIds, $targetShiftId, $newRelayId) {
                foreach ($resolvedEmployeeIds as $empId) {
                    EmployeeShiftAssignment::updateOrCreate(
                        ['employee_id' => $empId],
                        [
                            'shift_id' => $targetShiftId,
                            'from_date' => now()->toDateString(),
                            'to_date' => null
                        ]
                    );

                    // Also write to overrides table for relay-based flow
                    EmployeeShiftOverride::create([
                        'employee_id' => $empId,
                        'effective_from' => now()->toDateString(),
                        'shift_id' => $targetShiftId,
                        'reason' => 'Manual shift change',
                        'created_by' => auth()->id(),
                    ]);

                    Employee::where('id', $empId)->update(['relay_id' => $newRelayId]);
                }
            });

            $targetShift = \App\Models\Shift::find($targetShiftId);
            $targetShiftName = $targetShift ? $targetShift->shift_name : 'Unknown';

            $currentShiftName = null;
            if ($request->filled('current_shift_id')) {
                $currentShift = \App\Models\Shift::find($request->current_shift_id);
                if ($currentShift) {
                    $currentShiftName = $currentShift->shift_name;
                }
            }

            $count = count($resolvedEmployeeIds);
            $empWord = $count === 1 ? 'employee' : 'employees';

            if ($currentShiftName) {
                $message = "Shift changed for {$count} {$empWord} from {$currentShiftName} to {$targetShiftName}";
            } else {
                $message = "Shift changed for {$count} {$empWord} to {$targetShiftName}";
            }

            return response()->json([
                'status' => 200,
                'message' => $message
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    /**
     * Override/change shift for a single employee by their ID.
     */
    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'shift_id' => 'required|exists:shifts,id',
            ]);

            $employee = Employee::with('relay')->find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Employee not found.'
                ], 404);
            }

            if (!$employee->relay_id || !$employee->relay || !$employee->relay->is_rotating) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Shift rotation is not allowed for non-rotating/general shift employees.'
                ], 422);
            }

            if ($blockedResponse = $this->openDeploymentResponse(collect([$employee]))) {
                return $blockedResponse;
            }

            $targetShiftId = $request->shift_id;

            $newRelayId = $this->resolveRelayIdForShift($targetShiftId);

            if (!$newRelayId) {
                return $this->unmappedShiftResponse($targetShiftId);
            }

            DB::transaction(function () use ($employee, $targetShiftId, $newRelayId) {
                EmployeeShiftAssignment::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'shift_id' => $targetShiftId,
                        'from_date' => now()->toDateString(),
                        'to_date' => null
                    ]
                );

                // Also write to overrides table for relay-based flow
                EmployeeShiftOverride::create([
                    'employee_id' => $employee->id,
                    'effective_from' => now()->toDateString(),
                    'shift_id' => $targetShiftId,
                    'reason' => 'Shift override',
                    'created_by' => auth()->id(),
                ]);

                $employee->update(['relay_id' => $newRelayId]);
            });

            $targetShift = \App\Models\Shift::find($targetShiftId);
            $targetShiftName = $targetShift ? $targetShift->shift_name : 'Unknown';

            return response()->json([
                'status' => 200,
                'message' => "Shift override applied successfully. Shift changed to {$targetShiftName}."
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Display the monthly roster details for a specific employee.
     */
    public function show(Request $request, $id)
    {
        $request->merge(['employee_id' => $id]);
        return $this->monthlyRoster($request);
    }

    /**
     * Override/change shift for a single employee.
     */
    public function overrideShift(Request $request)
    {
        try {
            $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'shift_id' => 'required|exists:shifts,id',
            ]);

            $employee = Employee::with('relay')->find($request->employee_id);

            if (!$employee) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Employee not found.'
                ], 404);
            }

            if (!$employee->relay_id || !$employee->relay || !$employee->relay->is_rotating) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Shift rotation/override is not allowed for non-rotating/general shift employees.'
                ], 422);
            }

            if ($blockedResponse = $this->openDeploymentResponse(collect([$employee]))) {
                return $blockedResponse;
            }

            $targetShiftId = $request->shift_id;

            $newRelayId = $this->resolveRelayIdForShift($targetShiftId);

            if (!$newRelayId) {
                return $this->unmappedShiftResponse($targetShiftId);
            }

            DB::transaction(function () use ($employee, $targetShiftId, $newRelayId) {
                EmployeeShiftAssignment::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'shift_id' => $targetShiftId,
                        'from_date' => now()->toDateString(),
                        'to_date' => null
                    ]
                );

                // Also write to overrides table for relay-based flow
                EmployeeShiftOverride::create([
                    'employee_id' => $employee->id,
                    'effective_from' => now()->toDateString(),
                    'shift_id' => $targetShiftId,
                    'reason' => 'Shift override',
                    'created_by' => auth()->id(),
                ]);

                $employee->update(['relay_id' => $newRelayId]);
            });

            $targetShift = \App\Models\Shift::find($targetShiftId);
            $targetShiftName = $targetShift ? $targetShift->shift_name : 'Unknown';

            return response()->json([
                'status' => 200,
                'message' => "Shift override applied successfully. Shift changed to {$targetShiftName}."
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Swap shift between two employees.
     */
    public function swapShift(Request $request)
    {
        try {
            $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'swap_with_employee_id' => 'required|exists:employees,id|different:employee_id',
            ]);

            $employee1 = Employee::with('relay')->find($request->employee_id);
            $employee2 = Employee::with('relay')->find($request->swap_with_employee_id);

            $isEmp1Rotating = $employee1->relay_id && $employee1->relay && $employee1->relay->is_rotating;
            $isEmp2Rotating = $employee2->relay_id && $employee2->relay && $employee2->relay->is_rotating;

            if (!$isEmp1Rotating || !$isEmp2Rotating) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Shift swap is not allowed for non-rotating/general shift employees.'
                ], 422);
            }

            if ($blockedResponse = $this->openDeploymentResponse(collect([$employee1, $employee2]))) {
                return $blockedResponse;
            }

            $assignment1 = EmployeeShiftAssignment::where('employee_id', $employee1->id)->first();
            $assignment2 = EmployeeShiftAssignment::where('employee_id', $employee2->id)->first();

            // Resolve shift IDs from assignments or relay mapping fallback
            $shiftId1 = $assignment1 ? $assignment1->shift_id : $employee1->shift_id;
            $shiftId2 = $assignment2 ? $assignment2->shift_id : $employee2->shift_id;

            if (!$shiftId1) {
                return response()->json([
                    'status' => 422,
                    'message' => "Employee '{$employee1->name}' does not have a shift assigned."
                ], 422);
            }

            if (!$shiftId2) {
                return response()->json([
                    'status' => 422,
                    'message' => "Employee '{$employee2->name}' does not have a shift assigned."
                ], 422);
            }

            if ($shiftId1 === $shiftId2) {
                return response()->json([
                    'status' => 422,
                    'message' => "Both employees already have the same shift assigned."
                ], 422);
            }

            if ((int) $employee1->relay_id === (int) $employee2->relay_id) {
                return response()->json([
                    'status' => 422,
                    'message' => "Both employees belong to the same relay. A swap exchanges their relays, so they must start in different relays."
                ], 422);
            }

            $relayId1 = $employee1->relay_id;
            $relayId2 = $employee2->relay_id;

            DB::transaction(function () use ($employee1, $employee2, $shiftId1, $shiftId2, $relayId1, $relayId2) {
                EmployeeShiftAssignment::updateOrCreate(
                    ['employee_id' => $employee1->id],
                    ['shift_id' => $shiftId2, 'from_date' => now()->toDateString(), 'to_date' => null]
                );

                EmployeeShiftAssignment::updateOrCreate(
                    ['employee_id' => $employee2->id],
                    ['shift_id' => $shiftId1, 'from_date' => now()->toDateString(), 'to_date' => null]
                );

                // Also write to overrides table for relay-based flow
                EmployeeShiftOverride::create([
                    'employee_id' => $employee1->id,
                    'effective_from' => now()->toDateString(),
                    'shift_id' => $shiftId2,
                    'reason' => 'Shift swap',
                    'created_by' => auth()->id(),
                ]);
                EmployeeShiftOverride::create([
                    'employee_id' => $employee2->id,
                    'effective_from' => now()->toDateString(),
                    'shift_id' => $shiftId1,
                    'reason' => 'Shift swap',
                    'created_by' => auth()->id(),
                ]);

                // Also swap their relays
                $employee1->update(['relay_id' => $relayId2]);
                $employee2->update(['relay_id' => $relayId1]);
            });

            $shift1 = \App\Models\Shift::find($shiftId1);
            $shift2 = \App\Models\Shift::find($shiftId2);

            $shiftName1 = $shift1 ? $shift1->shift_name : 'Unknown';
            $shiftName2 = $shift2 ? $shift2->shift_name : 'Unknown';

            return response()->json([
                'status' => 200,
                'message' => "Shifts swapped successfully. {$employee1->name} is now on {$shiftName2} and {$employee2->name} is now on {$shiftName1}."
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get monthly roster details for a specific employee.
     */
    public function monthlyRoster(Request $request)
    {
        try {
            $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'month' => 'nullable', // can be YYYY-MM or MM format
                'year' => 'nullable|integer',
            ]);

            $employee = Employee::with(['department', 'site', 'designation'])->find($request->employee_id);

            $monthInput = $request->input('month', now()->format('m'));
            $yearInput = $request->input('year', now()->format('Y'));

            // Parse monthInput if in YYYY-MM format
            if (preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
                $parts = explode('-', $monthInput);
                $yearInput = $parts[0];
                $monthInput = $parts[1];
            }

            // Standardize month to 2 digits
            $monthInput = sprintf('%02d', $monthInput);

            $startOfMonth = Carbon::create($yearInput, $monthInput, 1)->startOfMonth();
            $endOfMonth = Carbon::create($yearInput, $monthInput, 1)->endOfMonth();
            $daysInMonth = $startOfMonth->daysInMonth;

            // Fetch Holidays that apply to this employee in this month: the
            // site's own, plus the establishment-wide ones (site_id NULL),
            // which this roster was silently skipping. Keyed by date, so a
            // date carrying more than one row still paints a single day - the
            // site row wins the label over the general one.
            $holidays = \App\Models\Holiday::where('is_active', 1)
                ->where(function ($q) use ($employee) {
                    $q->whereNull('site_id')
                        ->orWhere('site_id', $employee->site_id);
                })
                ->whereYear('holiday_date', $yearInput)
                ->whereMonth('holiday_date', $monthInput)
                ->orderByRaw('site_id IS NULL DESC')
                ->get()
                ->keyBy(fn($h) => Carbon::parse($h->holiday_date)->toDateString());

            // Fetch approved Leaves for the employee in this month
            $leaves = \App\Models\EmployeeLeave::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                    $startStr = $startOfMonth->toDateString();
                    $endStr = $endOfMonth->toDateString();
                    $q->whereBetween('from_date', [$startStr, $endStr])
                        ->orWhereBetween('to_date', [$startStr, $endStr])
                        ->orWhere(function ($sub) use ($startStr, $endStr) {
                            $sub->where('from_date', '<=', $startStr)
                                ->where('to_date', '>=', $endStr);
                        });
                })
                ->get();

            // Fetch non-working days
            $nonWorkingDays = \App\Models\WorkingDay::where('is_working', 0)
                ->pluck('day')
                ->map(fn($day) => strtolower($day))
                ->toArray();

            // Find employee's first assignment date
            $assignment = EmployeeShiftAssignment::where('employee_id', $employee->id)->first();
            $firstAssignmentDate = null;
            $earliestHistory = EmployeeShiftHistory::where('employee_id', $employee->id)
                ->orderBy('change_date', 'asc')
                ->orderBy('id', 'asc')
                ->first();
            if ($earliestHistory) {
                $firstAssignmentDate = $earliestHistory->change_date;
            } elseif ($assignment) {
                $firstAssignmentDate = $assignment->from_date ?: ($assignment->created_at ? $assignment->created_at->toDateString() : now()->toDateString());
            }

            // Initialize counts
            $shiftsSummary = [];
            $allShifts = \App\Models\Shift::all();
            foreach ($allShifts as $sh) {
                $shiftsSummary[$sh->shift_name] = 0;
            }
            $offCount = 0;
            $holidayCount = 0;
            $leaveCount = 0;

            $timeline = [];

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateObj = Carbon::create($yearInput, $monthInput, $day);
                $dateStr = $dateObj->toDateString();

                $status = 'Off';
                $label = 'Rest Day';
                $pillColor = 'off';
                $shiftName = null;

                // 0. Check if before first assignment
                if ($firstAssignmentDate && $dateStr < $firstAssignmentDate) {
                    $status = 'Unassigned';
                    $label = null;
                    $pillColor = null;
                    $shiftName = null;
                }
                // 1. Check Holiday
                elseif (isset($holidays[$dateStr])) {
                    $status = 'Holiday';
                    $label = $holidays[$dateStr]->holiday_name ?: 'Public Holiday';
                    $pillColor = 'holiday';
                    $holidayCount++;
                }
                // 2. Check Leave
                else {
                    $matchingLeave = $leaves->first(function ($l) use ($dateStr) {
                        return $dateStr >= $l->from_date && $dateStr <= $l->to_date;
                    });

                    if ($matchingLeave) {
                        $status = 'Leave';
                        $label = $matchingLeave->reason ?: 'On Leave';
                        $pillColor = 'leave';
                        $leaveCount++;
                    }
                    // 3. Check Off vs Shift
                    else {
                        $dayOfWeek = strtolower($dateObj->format('l'));
                        $isWeekend = in_array($dayOfWeek, $nonWorkingDays);

                        if ($isWeekend) {
                            $status = 'Off';
                            $label = 'Rest Day';
                            $pillColor = 'off';
                            $offCount++;
                        } else {
                            $shift = $this->getShiftForDate($employee, $dateObj);
                            if ($shift) {
                                $status = 'Shift';
                                $shiftName = $shift->shift_name;

                                $startTimeStr = $shift->start_time ? Carbon::parse($shift->start_time)->format('h:i A') : '';
                                $endTimeStr = $shift->end_time ? Carbon::parse($shift->end_time)->format('h:i A') : '';
                                $label = $startTimeStr && $endTimeStr ? "{$startTimeStr} - {$endTimeStr}" : 'Working Day';
                                $pillColor = 'shift-' . strtolower(str_replace(' ', '-', $shift->shift_name));

                                if (isset($shiftsSummary[$shift->shift_name])) {
                                    $shiftsSummary[$shift->shift_name]++;
                                } else {
                                    $shiftsSummary[$shift->shift_name] = 1;
                                }
                            } else {
                                $status = 'Off';
                                $label = 'Rest Day';
                                $pillColor = 'off';
                                $offCount++;
                            }
                        }
                    }
                }

                $timeline[] = [
                    'day_number' => sprintf('%02d', $day),
                    'date' => $dateStr,
                    'formatted_date' => $dateObj->format('d M'),
                    'day_name' => $dateObj->format('D'),
                    'status' => $status,
                    'shift_name' => $shiftName,
                    'label' => $label,
                    'pill_color' => $pillColor
                ];
            }

            // Group timeline into 7-day weeks and summarize weekly shifts
            $weeks = [];
            $dayIndex = 0;
            $weekNum = 1;
            while ($dayIndex < count($timeline)) {
                $weekDays = array_slice($timeline, $dayIndex, 7);
                $startDay = $weekDays[0]['day_number'];
                $endDay = end($weekDays)['day_number'];

                // Count shifts and statuses in this week
                $shiftCounts = [];
                $statusCounts = [];

                foreach ($weekDays as $day) {
                    if ($day['status'] === 'Shift' && $day['shift_name']) {
                        $shiftCounts[$day['shift_name']] = ($shiftCounts[$day['shift_name']] ?? 0) + 1;
                    } elseif ($day['status'] !== 'Unassigned') {
                        $statusCounts[$day['status']] = ($statusCounts[$day['status']] ?? 0) + 1;
                    }
                }

                $weekShiftName = null;
                $weekLabel = null;
                $weekPillColor = null;

                if (!empty($shiftCounts)) {
                    // Get the most frequent shift name in this week
                    arsort($shiftCounts);
                    $weekShiftName = key($shiftCounts);

                    // Find the shift details to get the time label
                    $shiftObj = $allShifts->firstWhere('shift_name', $weekShiftName);
                    if ($shiftObj) {
                        $startTimeStr = $shiftObj->start_time ? Carbon::parse($shiftObj->start_time)->format('h:i A') : '';
                        $endTimeStr = $shiftObj->end_time ? Carbon::parse($shiftObj->end_time)->format('h:i A') : '';
                        $weekLabel = $startTimeStr && $endTimeStr ? "{$startTimeStr} - {$endTimeStr}" : 'Working Days';
                        $weekPillColor = 'shift-' . strtolower(str_replace(' ', '-', $weekShiftName));
                    }
                } else {
                    // No shifts, find the most frequent non-shift status (Leave, Holiday, Off)
                    if (!empty($statusCounts)) {
                        arsort($statusCounts);
                        $primaryStatus = key($statusCounts);
                        if ($primaryStatus === 'Leave') {
                            $weekShiftName = 'Leave';
                            $weekLabel = 'On Leave';
                            $weekPillColor = 'leave';
                        } elseif ($primaryStatus === 'Holiday') {
                            $weekShiftName = 'Holiday';
                            $weekLabel = 'Public Holidays';
                            $weekPillColor = 'holiday';
                        } else {
                            $weekShiftName = 'Off';
                            $weekLabel = 'Rest Days';
                            $weekPillColor = 'off';
                        }
                    }
                }

                $weeks[] = [
                    'week_name' => "Week {$weekNum}",
                    'days_range' => "Days: {$startDay} to {$endDay}",
                    'shift_name' => $weekShiftName,
                    'label' => $weekLabel,
                    'pill_color' => $weekPillColor
                ];

                $dayIndex += 7;
                $weekNum++;
            }

            // Prepare Monthly Summary capsules
            $monthlySummary = [];
            foreach ($shiftsSummary as $name => $count) {
                $monthlySummary[] = [
                    'label' => $name,
                    'days' => $count,
                ];
            }
            // Add Off capsule
            $monthlySummary[] = [
                'label' => 'Off',
                'days' => $offCount + $holidayCount + $leaveCount,
            ];

            return response()->json([
                'status' => 200,
                'message' => 'Monthly roster details retrieved successfully',
                'data' => [
                    'employee' => [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'employee_code' => $employee->employee_code,
                        'department' => optional($employee->department)->name,
                        'designation' => optional($employee->designation)->name,
                        'site' => optional($employee->site)->site_name,
                    ],
                    'month_year' => $startOfMonth->format('F Y'),
                    'monthly_summary' => $monthlySummary,
                    'timeline_weeks' => $weeks
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Employees who are still actively deployed on a shift plan that has not been
     * closed yet, keyed by employee_id.
     *
     * A shift change rewrites the employee's relay and writes an open-ended
     * override, so the shift they resolve to stops matching the plan they are
     * standing on. While that plan is still open its workforce, machine
     * allocations and attendance would silently go stale, so the change is
     * blocked until the plan is closed or the employee is removed from it.
     *
     * @param  array|\Illuminate\Support\Collection  $employeeIds
     * @return \Illuminate\Support\Collection
     */
    private function openDeploymentsFor($employeeIds)
    {
        return ShiftWorkforceDeployment::whereIn('employee_id', $employeeIds)
            ->active()
            ->whereHas('shiftPlan', function ($q) {
                $q->notClosed();
            })
            ->with('shiftPlan.shift')
            ->get()
            ->keyBy('employee_id');
    }

    /**
     * Human-readable reason why this employee's shift cannot be changed.
     */
    private function openDeploymentMessage(Employee $employee, ShiftWorkforceDeployment $deployment)
    {
        $plan = $deployment->shiftPlan;
        $shiftName = ($plan && $plan->shift) ? $plan->shift->shift_name : 'another shift';

        $where = "'{$employee->name}' is already deployed in {$shiftName}";

        if ($plan && $plan->planning_date) {
            $where .= ' on ' . $plan->planning_date->format('Y-m-d');
        }

        if ($plan && $plan->reference_no) {
            $where .= " ({$plan->reference_no})";
        }

        return $where . ', and that shift plan is not closed yet. Close the shift plan or remove the employee from its workforce before changing their shift.';
    }

    /**
     * 422 listing every selected employee that is blocked by an open deployment,
     * or null when none of them are.
     *
     * @param  \Illuminate\Support\Collection  $employees
     */
    private function openDeploymentResponse($employees)
    {
        $deployments = $this->openDeploymentsFor($employees->pluck('id')->all());

        if ($deployments->isEmpty()) {
            return null;
        }

        $blocked = [];
        foreach ($employees as $employee) {
            $deployment = $deployments->get($employee->id);
            if ($deployment) {
                $blocked[] = $this->openDeploymentMessage($employee, $deployment);
            }
        }

        if (empty($blocked)) {
            return null;
        }

        return response()->json([
            'status' => 422,
            'message' => count($blocked) === 1
                ? 'Shift cannot be changed. Employee ' . $blocked[0]
                : 'Shift cannot be changed for ' . count($blocked) . ' of the selected employees.',
            'data' => ['blocked' => $blocked],
        ], 422);
    }

    /**
     * Determine the active shift for an employee on a given date.
     */
    private function getShiftForDate(Employee $employee, Carbon $date)
    {
        return $employee->getShiftForDate($date->toDateString());
    }

    /**
     * Resolve the relay ID corresponding to a target shift ID.
     */
    private function resolveRelayIdForShift($targetShiftId)
    {
        $today = now()->toDateString();
        $mapping = \App\Models\RelayShiftMapping::where('shift_id', $targetShiftId)
            ->where('week_start_date', '<=', $today)
            ->where('week_end_date', '>=', $today)
            ->first();

        if (!$mapping) {
            $mapping = \App\Models\RelayShiftMapping::where('shift_id', $targetShiftId)
                ->orderBy('week_start_date', 'desc')
                ->first();
        }

        return $mapping ? $mapping->relay_id : null;
    }

    /**
     * 422 for a target shift that no relay owns.
     *
     * Changing an employee's shift also moves them into that shift's relay. If no
     * relay is mapped to the shift the employee would keep their old relay while
     * resolving to the new shift, which puts one relay on two shifts at once.
     */
    private function unmappedShiftResponse($targetShiftId)
    {
        $shift = \App\Models\Shift::find($targetShiftId);
        $shiftName = $shift ? $shift->shift_name : "#{$targetShiftId}";

        return response()->json([
            'status' => 422,
            'message' => "No relay is mapped to shift '{$shiftName}'. Assign a relay to this shift in Relay Master first, then change the employee's shift.",
        ], 422);
    }
}
