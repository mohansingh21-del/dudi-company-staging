<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeShiftHistoryResource;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftHistory;
use App\Models\Employee;
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
            $query = Employee::where('is_active', 1)
                ->where('relay_shift', '!=', 'general')
                ->whereHas('currentShiftAssignment')
                ->with(['currentShiftAssignment.shift', 'department', 'site', 'designation', 'supervisor']);

            if ($request->filled('shift_id')) {
                $query->whereHas('currentShiftAssignment', function ($q) use ($request) {
                    $q->where('shift_id', $request->shift_id);
                });
            }

            if ($request->filled('from_date')) {
                $query->whereHas('currentShiftAssignment', function ($q) use ($request) {
                    $q->where(function ($sub) use ($request) {
                        $sub->whereNotNull('from_date')
                            ->where('from_date', '>=', $request->from_date)
                            ->orWhere(function ($sub2) use ($request) {
                                $sub2->whereNull('from_date')
                                     ->whereDate('created_at', '>=', $request->from_date);
                            });
                    });
                });
            }

            if ($request->filled('to_date')) {
                $query->whereHas('currentShiftAssignment', function ($q) use ($request) {
                    $q->where(function ($sub) use ($request) {
                        $sub->whereNotNull('from_date')
                            ->where('from_date', '<=', $request->to_date)
                            ->orWhere(function ($sub2) use ($request) {
                                $sub2->whereNull('from_date')
                                     ->whereDate('created_at', '<=', $request->to_date);
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
                    'shift' => optional(optional($employee->currentShiftAssignment)->shift)->shift_name,
                    'relay' => $employee->relay_shift,
                    'relay_shift' => $employee->relay_shift,
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

            $resolvedEmployeeIds = [];
            foreach ($identifiers as $identifier) {
                $employee = Employee::where('id', $identifier)
                    ->orWhere('employee_code', $identifier)
                    ->first();

                if (!$employee) {
                    return response()->json([
                        'status' => 422,
                        'message' => "Employee '{$identifier}' not found."
                    ], 422);
                }

                if ($employee->relay_shift === 'general') {
                    continue;
                }

                $resolvedEmployeeIds[] = $employee->id;
            }

            if (empty($resolvedEmployeeIds)) {
                return response()->json([
                    'status' => 422,
                    'message' => 'No active relay employees selected for shift rotation.'
                ], 422);
            }

            DB::transaction(function () use ($resolvedEmployeeIds, $targetShiftId) {
                foreach ($resolvedEmployeeIds as $empId) {
                    EmployeeShiftAssignment::updateOrCreate(
                        ['employee_id' => $empId],
                        [
                            'shift_id' => $targetShiftId,
                            'from_date' => now()->toDateString(),
                            'to_date' => null
                        ]
                    );
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

            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Employee not found.'
                ], 404);
            }

            if ($employee->relay_shift === 'general') {
                return response()->json([
                    'status' => 422,
                    'message' => 'Shift rotation is not allowed for general shift employees.'
                ], 422);
            }

            $targetShiftId = $request->shift_id;

            DB::transaction(function () use ($employee, $targetShiftId) {
                EmployeeShiftAssignment::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'shift_id' => $targetShiftId,
                        'from_date' => now()->toDateString(),
                        'to_date' => null
                    ]
                );
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

            $employee = Employee::find($request->employee_id);

            if (!$employee) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Employee not found.'
                ], 404);
            }

            if ($employee->relay_shift === 'general') {
                return response()->json([
                    'status' => 422,
                    'message' => 'Shift rotation/override is not allowed for general shift employees.'
                ], 422);
            }

            $targetShiftId = $request->shift_id;

            DB::transaction(function () use ($employee, $targetShiftId) {
                EmployeeShiftAssignment::updateOrCreate(
                    ['employee_id' => $employee->id],
                    [
                        'shift_id' => $targetShiftId,
                        'from_date' => now()->toDateString(),
                        'to_date' => null
                    ]
                );
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

            $employee1 = Employee::find($request->employee_id);
            $employee2 = Employee::find($request->swap_with_employee_id);

            if ($employee1->relay_shift === 'general' || $employee2->relay_shift === 'general') {
                return response()->json([
                    'status' => 422,
                    'message' => 'Shift swap is not allowed for general shift employees.'
                ], 422);
            }

            $assignment1 = EmployeeShiftAssignment::where('employee_id', $employee1->id)->first();
            $assignment2 = EmployeeShiftAssignment::where('employee_id', $employee2->id)->first();

            if (!$assignment1) {
                return response()->json([
                    'status' => 422,
                    'message' => "Employee '{$employee1->name}' does not have a shift assignment."
                ], 422);
            }

            if (!$assignment2) {
                return response()->json([
                    'status' => 422,
                    'message' => "Employee '{$employee2->name}' does not have a shift assignment."
                ], 422);
            }

            if ($assignment1->shift_id === $assignment2->shift_id) {
                return response()->json([
                    'status' => 422,
                    'message' => "Both employees already have the same shift assigned."
                ], 422);
            }

            $shiftId1 = $assignment1->shift_id;
            $shiftId2 = $assignment2->shift_id;

            DB::transaction(function () use ($assignment1, $assignment2, $shiftId1, $shiftId2) {
                $assignment1->update([
                    'shift_id' => $shiftId2,
                    'from_date' => now()->toDateString(),
                    'to_date' => null
                ]);

                $assignment2->update([
                    'shift_id' => $shiftId1,
                    'from_date' => now()->toDateString(),
                    'to_date' => null
                ]);
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

            // Fetch Holidays for the site of the employee in this month
            $holidays = \App\Models\Holiday::where('site_id', $employee->site_id)
                ->where('is_active', 1)
                ->whereYear('holiday_date', $yearInput)
                ->whereMonth('holiday_date', $monthInput)
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
     * Determine the active shift for an employee on a given date.
     */
    private function getShiftForDate(Employee $employee, Carbon $date)
    {
        $dateStr = $date->toDateString();

        // 1. Get the current assignment
        $assignment = EmployeeShiftAssignment::where('employee_id', $employee->id)->first();

        // 2. Check if the date falls within the current assignment
        if ($assignment) {
            $from = $assignment->from_date ?: ($assignment->created_at ? $assignment->created_at->toDateString() : now()->toDateString());
            $to = $assignment->to_date;
            if ($from && $dateStr >= $from && (is_null($to) || $dateStr <= $to)) {
                return $assignment->shift;
            }
        }

        // 3. Search shift histories for the active shift on this date
        $nextChange = EmployeeShiftHistory::where('employee_id', $employee->id)
            ->where('change_date', '>', $dateStr)
            ->orderBy('change_date', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if ($nextChange) {
            return $nextChange->old_shift_id ? \App\Models\Shift::find($nextChange->old_shift_id) : null;
        }

        $latestChange = EmployeeShiftHistory::where('employee_id', $employee->id)
            ->where('change_date', '<=', $dateStr)
            ->orderBy('change_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($latestChange) {
            return \App\Models\Shift::find($latestChange->new_shift_id);
        }

        // If the date is before the current assignment, and no history matches, they had no shift assigned
        if ($assignment) {
            $from = $assignment->from_date ?: ($assignment->created_at ? $assignment->created_at->toDateString() : now()->toDateString());
            if ($dateStr < $from) {
                return null;
            }
        }

        return $assignment ? $assignment->shift : null;
    }
}
