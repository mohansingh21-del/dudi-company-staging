<?php

namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Holiday;
use App\Models\AttendanceCorrection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Imports\AttendanceImport;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\AttendanceProcessed;
use App\Http\Resources\AttendanceResource;
use App\Http\Requests\UpdateAttendanceRequest;
class AttendanceController extends Controller
{

    /**
     * List all correction requests
     */
    public function listCorrections(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $status = $request->input('status', '0'); // Default pending

            $query = AttendanceCorrection::with(['user.employee.department', 'attendance'])
                ->where('status', $status);

            if ($limit) {
                $corrections = $query->orderBy('id', 'DESC')
                    ->paginate($limit, ['*'], 'page', $page);

                $corrections->getCollection()->transform(function ($correction) {
                    return [
                        'id' => $correction->id,
                        'date' => $correction->date,
                        'requested_login_time' => $correction->login_time ? \Carbon\Carbon::parse($correction->login_time)->format('h:i A') : '--:--',
                        'requested_logout_time' => $correction->logout_time ? \Carbon\Carbon::parse($correction->logout_time)->format('h:i A') : '--:--',
                        'requested_status' => $correction->is_approved, // 1=Present, 3=Half Day, etc.
                        'remarks' => $correction->remarks,
                        'employee' => [
                            'id' => $correction->user->id,
                            'name' => $correction->user->employee->name ?? 'N/A',
                            'email' => $correction->user->email,
                            'phone' => $correction->user->employee->phone ?? 'N/A',
                            'department' => $correction->user->employee->department->name ?? 'N/A',
                        ],
                        'original_attendance' => $correction->attendance ? [
                            'id' => $correction->attendance->id,
                            'login_time' => $correction->attendance->login_time ? \Carbon\Carbon::parse($correction->attendance->login_time)->format('h:i A') : '--:--',
                            'logout_time' => $correction->attendance->logout_time ? \Carbon\Carbon::parse($correction->attendance->logout_time)->format('h:i A') : '--:--',
                            'is_approved' => $correction->attendance->is_approved,
                        ] : null,
                        'status' => $correction->status == 0 ? "Pending" : ($correction->status == 1 ? "Approved" : "Rejected"),
                        'created_at' => $correction->created_at->toDateTimeString(),
                    ];
                });

                $response = [
                    'data' => $corrections->items(),
                    'pagination' => [
                        'total' => $corrections->total(),
                        'current_page' => $corrections->currentPage(),
                        'per_page' => $corrections->perPage(),
                        'last_page' => $corrections->lastPage(),
                        'from' => $corrections->firstItem(),
                        'to' => $corrections->lastItem(),
                    ]
                ];
            } else {
                $corrections = $query->orderBy('id', 'DESC')->get();

                $corrections->transform(function ($correction) {
                    return [
                        'id' => $correction->id,
                        'date' => $correction->date,
                        'requested_login_time' => $correction->login_time ? \Carbon\Carbon::parse($correction->login_time)->format('h:i A') : '--:--',
                        'requested_logout_time' => $correction->logout_time ? \Carbon\Carbon::parse($correction->logout_time)->format('h:i A') : '--:--',
                        'requested_status' => $correction->is_approved, // 1=Present, 3=Half Day, etc.
                        'remarks' => $correction->remarks,
                        'employee' => [
                            'id' => $correction->user->id,
                            'name' => $correction->user->employee->name ?? 'N/A',
                            'email' => $correction->user->email,
                            'phone' => $correction->user->employee->phone ?? 'N/A',
                            'department' => $correction->user->employee->department->name ?? 'N/A',
                        ],
                        'original_attendance' => $correction->attendance ? [
                            'id' => $correction->attendance->id,
                            'login_time' => $correction->attendance->login_time ? \Carbon\Carbon::parse($correction->attendance->login_time)->format('h:i A') : '--:--',
                            'logout_time' => $correction->attendance->logout_time ? \Carbon\Carbon::parse($correction->attendance->logout_time)->format('h:i A') : '--:--',
                            'is_approved' => $correction->attendance->is_approved,
                        ] : null,
                        'status' => $correction->status == 0 ? "Pending" : ($correction->status == 1 ? "Approved" : "Rejected"),
                        'created_at' => $correction->created_at->toDateTimeString(),
                    ];
                });

                $response = [
                    'data' => $corrections,
                    'pagination' => [
                        'total' => $corrections->count(),
                        'current_page' => 1,
                        'per_page' => $corrections->count(),
                        'last_page' => 1,
                        'from' => $corrections->isEmpty() ? 0 : 1,
                        'to' => $corrections->count(),
                    ]
                ];
            }

            return response()->json([
                'status' => 200,
                'message' => 'Correction requests fetched successfully',
                'data' => $response['data'],
                'pagination' => $response['pagination'] ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'An error occurred while processing the request: ' . $e->getMessage(),
            ]);
        }
    }

    public function update(UpdateAttendanceRequest $request, $id = null)
    {
        try {
            $data = $request->validated();

            $attendance = null;
            $employeeId = $request->input('employee_id');
            $date = $request->input('date');

            if ($employeeId && $date) {
                $attendance = AttendanceProcessed::where('employee_id', $employeeId)
                    ->where('date', $date)
                    ->first();
            } else if ($id) {
                $attendance = AttendanceProcessed::find($id);
                if (!$attendance && $date) {
                    // Try treating ID as employee ID
                    $attendance = AttendanceProcessed::where('employee_id', $id)
                        ->where('date', $date)
                        ->first();
                }
            }

            $empId = $attendance ? $attendance->employee_id : ($employeeId ?? $id);
            $employee = \App\Models\Employee::find($empId);
            if (!$employee) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Employee not found'
                ], 404);
            }

            // Update employee site_id if provided
            if ($request->filled('site_id')) {
                $employee->update(['site_id' => $request->site_id]);
            }

            $attendanceDate = $attendance
                ? Carbon::parse($attendance->date)->format('Y-m-d')
                : $date;
            if (!$attendanceDate) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Date is required for updating attendance'
                ], 422);
            }

            $checkIn = null;
            $checkOut = null;

            if ($request->filled('check_in')) {
                try {
                    $checkIn = Carbon::parse($attendanceDate . ' ' . $request->check_in);
                } catch (\Exception $e) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Invalid check-in time format'
                    ], 422);
                }
            }

            if ($request->filled('check_out')) {
                try {
                    $checkOut = Carbon::parse($attendanceDate . ' ' . $request->check_out);
                } catch (\Exception $e) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Invalid check-out time format'
                    ], 422);
                }
            }

            if ($checkIn && $checkOut && $checkOut->lessThanOrEqualTo($checkIn)) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Check-out must be greater than check-in'
                ], 422);
            }

            $status = strtolower($data['attendance_status']);
            $dbStatus = $status;
            if ($status === 'exception') {
                $dbStatus = 'absent';
            }

            // Resolve shift
            $shift = $attendance ? $attendance->shift : null;
            $shiftId = $attendance ? $attendance->shift_id : null;
            if (!$shift) {
                $shiftAssignment = \App\Models\EmployeeShiftAssignment::where('employee_id', $employee->id)->first();
                $shiftId = $shiftAssignment ? $shiftAssignment->shift_id : null;
                $shift = $shiftId ? \App\Models\Shift::find($shiftId) : null;
            }

            $workingHours = 0.0;
            $lateMinutes = 0;
            $earlyExitMinutes = 0;

            if ($checkIn && $checkOut) {
                $workingHours = round($checkIn->diffInMinutes($checkOut) / 60, 2);

                if ($shift) {
                    $shiftStart = Carbon::parse($attendanceDate . ' ' . $shift->start_time);
                    $shiftEnd = Carbon::parse($attendanceDate . ' ' . $shift->end_time);

                    if ($checkIn->gt($shiftStart)) {
                        $lateMinutes = $shiftStart->diffInMinutes($checkIn);
                    }

                    if ($checkOut->lt($shiftEnd)) {
                        $earlyExitMinutes = $checkOut->diffInMinutes($shiftEnd);
                    }
                }
            }

            $remarks = $request->input('remarks');
            if ($status === 'exception') {
                $remarks = trim('[Exception] ' . ($remarks ?? ''));
            }

            if ($attendance) {
                $attendance->update([
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'working_hours' => $workingHours,
                    'late_minutes' => $lateMinutes,
                    'early_exit_minutes' => $earlyExitMinutes,
                    'attendance_status' => $dbStatus,
                    'remarks' => $remarks,
                ]);
                $attendance->refresh();
            } else {
                $attendance = AttendanceProcessed::create([
                    'employee_id' => $employee->id,
                    'shift_id' => $shiftId,
                    'date' => $attendanceDate,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'working_hours' => $workingHours,
                    'late_minutes' => $lateMinutes,
                    'early_exit_minutes' => $earlyExitMinutes,
                    'attendance_status' => $dbStatus,
                    'remarks' => $remarks,
                ]);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Attendance updated successfully',
                'data' => [
                    'id' => $attendance->id,
                    'employee_id' => $attendance->employee_id,
                    'date' => $attendance->date,
                    'check_in' => $attendance->check_in ? Carbon::parse($attendance->check_in)->toDateTimeString() : null,
                    'check_out' => $attendance->check_out ? Carbon::parse($attendance->check_out)->toDateTimeString() : null,
                    'working_hours' => (float) $attendance->working_hours,
                    'attendance_status' => $attendance->attendance_status,
                    'remarks' => $attendance->remarks,
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
     * Get Attendance Report (Summary + History)
     */
    public function getAttendanceReport(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);

            $date = $request->input('date', Carbon::now()->format('Y-m-d'));
            $excludedRoles = ['Super Admin', 'CEO'];
            $lateThreshold = '09:30:00';

            // 1. Calculate Summary
            $totalEmployees = User::whereHas('employee')
                ->whereDoesntHave('roles', function ($q) use ($excludedRoles) {
                    $q->whereIn('name', $excludedRoles);
                })->count();

            $presentToday = Attendance::where('date', $date)
                ->where('is_approved', '1')
                ->whereHas('user', function ($q) use ($excludedRoles) {
                    $q->whereDoesntHave('roles', function ($rq) use ($excludedRoles) {
                        $rq->whereIn('name', $excludedRoles);
                    });
                })->count();

            $lateArrivals = Attendance::where('date', $date)
                ->where('login_time', '>', $lateThreshold)
                ->whereHas('user', function ($q) use ($excludedRoles) {
                    $q->whereDoesntHave('roles', function ($rq) use ($excludedRoles) {
                        $rq->whereIn('name', $excludedRoles);
                    });
                })->count();

            $absent = $totalEmployees - $presentToday;

            $summary = [
                'total_employees' => $totalEmployees,
                'present_today' => $presentToday,
                'late_arrivals' => $lateArrivals,
                'absent' => $absent < 0 ? 0 : $absent,
            ];

            // 2. Fetch History Query
            $query = Attendance::with(['user.employee.department'])
                ->whereHas('user', function ($q) use ($excludedRoles) {
                    $q->whereDoesntHave('roles', function ($rq) use ($excludedRoles) {
                        $rq->whereIn('name', $excludedRoles);
                    });
                });

            // Filters
            if ($request->has('date')) {
                $query->where('date', $request->date);
            }

            if ($request->has('department_id')) {
                $query->whereHas('user.employee', function ($q) use ($request) {
                    $q->where('department_id', $request->department_id);
                });
            }

            if ($request->has('branch_id')) {
                $query->whereHas('user.employee', function ($q) use ($request) {
                    $q->whereJsonContains('branch_ids', (string) $request->branch_id);
                });
            }

            if ($search) {
                $query->whereHas('user.employee', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%");
                });
            }

            if ($limit) {
                $attendances = $query->orderBy('date', 'DESC')
                    ->paginate($limit, ['*'], 'page', $page);

                $historyData = $attendances->getCollection();
                $pagination = [
                    'total' => $attendances->total(),
                    'current_page' => $attendances->currentPage(),
                    'per_page' => $attendances->perPage(),
                    'last_page' => $attendances->lastPage(),
                    'from' => $attendances->firstItem(),
                    'to' => $attendances->lastItem(),
                ];
            } else {
                $historyData = $query->orderBy('date', 'DESC')->get();
                $pagination = [
                    'total' => $historyData->count(),
                    'current_page' => 1,
                    'per_page' => $historyData->count(),
                    'last_page' => 1,
                    'from' => $historyData->isEmpty() ? 0 : 1,
                    'to' => $historyData->count(),
                ];
            }

            $historyData->transform(function ($item) {
                $workHours = '0h 0m';
                if ($item->login_time && $item->logout_time) {
                    $start = Carbon::parse($item->login_time);
                    $end = Carbon::parse($item->logout_time);
                    $diff = $start->diff($end);
                    $workHours = $diff->format('%hh %im');
                }

                $statusLabel = 'Pending';
                switch ($item->is_approved) {
                    case '1':
                        $statusLabel = 'Present';
                        break;
                    case '2':
                        $statusLabel = 'Absent';
                        break;
                    case '3':
                        $statusLabel = 'Half Day';
                        break;
                    case '4':
                        $statusLabel = 'Leave';
                        break;
                    case '5':
                        $statusLabel = 'Rest Day';
                        break;
                }

                return [
                    'id' => $item->id,
                    'employee_name' => $item->user->employee->name ?? 'N/A',
                    'employee_id' => $item->user->employee->id ?? 'N/A',
                    'date' => $item->date,
                    'department' => $item->user->employee->department->name ?? 'N/A',
                    'check_in' => $item->login_time ? Carbon::parse($item->login_time)->format('h:i A') : '--:--',
                    'check_out' => $item->logout_time ? Carbon::parse($item->logout_time)->format('h:i A') : '--:--',
                    'work_hours' => $workHours,
                    'status' => $statusLabel,
                    'is_approved' => $item->is_approved,
                ];
            });

            return response()->json([
                'status' => 200,
                'message' => 'Attendance report fetched successfully',
                'summary' => $summary,
                'history' => $historyData,
                'pagination' => $pagination
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'An error occurred while processing the request: ' . $e->getMessage(),
            ]);
        }
    }


    /**
     * Approve or Reject Correction Request
     */
    public function updateCorrectionStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:attendance_corrections,id',
            'status' => 'required|in:1,2', // 1=approved, 2=rejected
            'admin_comments' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $correction = AttendanceCorrection::findOrFail($request->id);

        if ($correction->status != '0') {
            return $this->errorResponse('This request has already been processed.', 400);
        }

        DB::beginTransaction();
        try {
            $correction->update([
                'status' => $request->status,
                'admin_comments' => $request->admin_comments,
            ]);

            if ($request->status == '1') {
                // If approved, update or create the attendance record
                Attendance::updateOrCreate(
                    [
                        'user_id' => $correction->user_id,
                        'date' => $correction->date,
                    ],
                    [
                        'login_time' => $correction->login_time,
                        'logout_time' => $correction->logout_time,
                        'is_approved' => $correction->is_approved,
                        'comments' => $correction->remarks,
                    ]
                );
            }

            DB::commit();
            return $this->successResponse($correction, $request->status == '1' ? 'Correction approved and attendance updated.' : 'Correction rejected.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('An error occurred while processing the request: ' . $e->getMessage(), 500);
        }
    }
    /**
     * Get Employee Monthly Attendance Details
     */
    public function getEmployeeDetails(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'month' => 'required|date_format:Y-m',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->first(), 422);
            }

            $user = User::with(['employee.department'])->findOrFail($request->user_id);
            $month = Carbon::parse($request->month);
            $daysInMonth = $month->daysInMonth;

            $attendances = Attendance::where('user_id', $request->user_id)
                ->whereYear('date', $month->year)
                ->whereMonth('date', $month->month)
                ->get()
                ->keyBy('date');

            $history = [];
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateString = $month->copy()->day($day)->format('Y-m-d');
                $carbonDate = Carbon::parse($dateString);
                $isWeekend = $carbonDate->isWeekend();

                $record = $attendances->get($dateString);

                $status = '-';
                if ($record) {
                    switch ($record->is_approved) {
                        case '1':
                            $status = 'Present';
                            break;
                        case '2':
                            $status = 'Absent';
                            break;
                        case '3':
                            $status = 'Half Day';
                            break;
                        case '4':
                            $status = 'Leave';
                            break;
                        case '5':
                            $statusLabel = 'Rest Day';
                            break;
                    }
                } elseif ($isWeekend) {
                    $status = 'Weekend';
                }

                $duration = '0h 0m';
                if ($record && $record->login_time && $record->logout_time) {
                    $start = Carbon::parse($record->login_time);
                    $end = Carbon::parse($record->logout_time);
                    $duration = $start->diff($end)->format('%hh %im');
                }

                $history[] = [
                    'date' => $carbonDate->format('d/m/Y'),
                    'status' => $status,
                    'check_in' => $record && $record->login_time ? Carbon::parse($record->login_time)->format('H:i') : '--:--',
                    'check_out' => $record && $record->logout_time ? Carbon::parse($record->logout_time)->format('H:i') : '--:--',
                    'duration' => $duration,
                ];
            }

            return $this->successResponse([
                'employee' => [
                    'id' => $user->id,
                    'employee_id' => $user->employee->id ?? 'N/A',
                    'name' => $user->employee->name ?? 'N/A',
                    'department' => $user->employee->department->name ?? 'N/A',
                ],
                'month' => $month->format('F Y'),
                'history' => $history
            ], 'Employee attendance details retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('An error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function getEmployeeAttendanceDetails(Request $request, $employee_id)
    {
        try {
            $monthInput = $request->input('month', now()->month);
            $yearInput = $request->input('year', now()->year);

            if ($request->filled('date')) {
                try {
                    $parsed = Carbon::parse($request->date);
                    $monthInput = $parsed->month;
                    $yearInput = $parsed->year;
                } catch (\Exception $e) {
                    // ignore and use fallback/default
                }
            }

            $month = (int) $monthInput;
            $year = (int) $yearInput;

            $employee = \App\Models\Employee::with(['site', 'department', 'designation'])
                ->findOrFail($employee_id);

            $startDate = Carbon::create($year, $month, 1)->startOfDay();
            $endDate = $startDate->copy()->endOfMonth()->endOfDay();
            $daysInMonth = $startDate->daysInMonth;

            // Fetch processed attendance
            $attendanceRecords = AttendanceProcessed::where('employee_id', $employee_id)
                ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->get()
                ->keyBy(function ($item) {
                    return Carbon::parse($item->date)->format('Y-m-d');
                });

            // Fetch approved leaves
            $leaves = Leave::where('employee_id', $employee_id)
                ->where('status', 'approved')
                ->where(function ($q) use ($month, $year) {
                    $q->where(function ($q2) use ($month, $year) {
                        $q2->whereMonth('from_date', $month)->whereYear('from_date', $year);
                    })->orWhere(function ($q2) use ($month, $year) {
                        $q2->whereMonth('to_date', $month)->whereYear('to_date', $year);
                    });
                })
                ->with('leaveType')
                ->get();

            $leaveDays = [];
            $monthStart = $startDate->copy();
            $monthEnd = $endDate->copy();
            foreach ($leaves as $leave) {
                $from = Carbon::parse($leave->from_date)->max($monthStart);
                $to = Carbon::parse($leave->to_date)->min($monthEnd);
                $curr = $from->copy();
                while ($curr->lte($to)) {
                    $leaveDays[$curr->format('Y-m-d')] = [
                        'status' => 'Leave',
                        'leave_type' => optional($leave->leaveType)->name ?? 'Leave',
                        'is_paid' => optional($leave->leaveType)->leave_category === 'paid',
                    ];
                    $curr->addDay();
                }
            }

            // Fetch holidays
            $holidays = Holiday::whereMonth('holiday_date', $month)
                ->whereYear('holiday_date', $year)
                ->where('is_active', true)
                ->where(function ($q) use ($employee) {
                    $q->whereNull('site_id')
                        ->orWhere('site_id', $employee->site_id);
                })
                ->get()
                ->keyBy(function ($item) {
                    return Carbon::parse($item->holiday_date)->format('Y-m-d');
                });

            $history = [];
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $carbonDate = Carbon::create($year, $month, $day)->startOfDay();
                $dateString = $carbonDate->format('Y-m-d');
                $isWeekend = $carbonDate->isWeekend();

                $record = $attendanceRecords->get($dateString);

                $status = 'Absent';
                $checkIn = '--:--';
                $checkOut = '--:--';
                $duration = '0h 0m';
                $durationFormatted = '00:00';
                $workingHours = 0.0;
                $recordId = null;

                if ($record) {
                    $recordId = $record->id;
                    $status = ucfirst(str_replace('_', ' ', $record->attendance_status));
                    $checkIn = $record->check_in ? Carbon::parse($record->check_in)->format('H:i') : '--:--';
                    $checkOut = $record->check_out ? Carbon::parse($record->check_out)->format('H:i') : '--:--';
                    $workingHours = (float) $record->working_hours;

                    $hours = floor($workingHours);
                    $minutes = round(($workingHours - $hours) * 60);
                    $duration = sprintf("%dh %dm", $hours, $minutes);
                    $durationFormatted = sprintf("%02d:%02d", $hours, $minutes);
                } else if (isset($leaveDays[$dateString])) {
                    $status = 'Leave';
                } else if ($holidays->has($dateString)) {
                    $status = 'Holiday';
                } else if ($isWeekend) {
                    $status = 'Weekend';
                }

                $history[] = [
                    'date' => $dateString,
                    'formatted_date' => $carbonDate->format('d/m/Y'),
                    'status' => $status,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'duration' => $durationFormatted,
                    'duration_label' => $duration,
                    'working_hours' => $workingHours,
                    'attendance_processed_id' => $recordId,
                ];
            }

            return response()->json([
                'status' => 200,
                'message' => 'Employee attendance details fetched successfully',
                'data' => [
                    'employee' => [
                        'id' => $employee->id,
                        'employee_id' => $employee->id,
                        'name' => $employee->name,
                        'employee_code' => $employee->employee_code,
                        'department' => $employee->department->name ?? null,
                        'designation' => $employee->designation->name ?? null,
                        'site_name' => $employee->site->site_name ?? null,
                    ],
                    'month' => $startDate->format('F Y'),
                    'month_num' => $month,
                    'year' => $year,
                    'history' => $history,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function bulkUpload(Request $request)
    {
        try {

            $request->validate([
                'file' => 'required|mimes:xlsx,xls,csv'
            ]);

            Excel::import(
                new AttendanceImport(),
                $request->file('file')
            );

            return response()->json([
                'status' => 200,
                'message' => 'Attendance imported successfully.'
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'status' => 422,
                'message' => $e->getMessage()
            ], 422);
        }
    }
    public function show(int $id)
    {
        try {
            $employee = AttendanceProcessed::find($id);
            if (!$employee) {
                return response()->json(['status' => 404, 'message' => 'Employee not found']);
            }
            return response()->json(['status' => 200, 'data' => new AttendanceResource($employee)]);
        } catch (\Throwable $th) {
            return response()->json(['status' => 500, 'message' => $th->getMessage()]);
        }
    } /* |-------------------------------------------------------------------------- | Update Employee |-------------------------------------------------------------------------- */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'attendance_status' => 'required|in:present,absent,half_day,leave,rest_day',
            'remarks' => 'nullable|string'
        ]);

        $attendance = AttendanceProcessed::find($id);

        if (!$attendance) {
            return response()->json([
                'status' => 404,
                'message' => 'Attendance not found'
            ]);
        }

        $attendance->update([
            'attendance_status' => $request->attendance_status,
            'remarks' => $request->remarks
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Attendance status updated successfully',

        ]);
    }
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'attendance_ids' => 'required|array|min:1',
            'attendance_ids.*' => 'exists:attendance_processeds,id',
            'attendance_status' => 'required|in:present,absent,half_day,leave,rest_day',
            'remarks' => 'nullable|string'
        ]);
        if ($request->attendance_status === 'present') {

            $attendances = AttendanceProcessed::whereIn(
                'id',
                $request->attendance_ids
            )->get();

            foreach ($attendances as $attendance) {

                $hasLeave = Leave::where('employee_id', $attendance->employee_id)
                    ->where('status', 'approved')
                    ->whereDate('from_date', '<=', \Carbon\Carbon::parse($attendance->date)->format('Y-m-d'))
                    ->whereDate('to_date', '>=', \Carbon\Carbon::parse($attendance->date)->format('Y-m-d'))
                    ->exists();

                if ($hasLeave) {
                    return response()->json([
                        'status' => 422,
                        'message' => "Cannot mark attendance as Present. Approved leave exists for employee on {$attendance->date}."
                    ], 422);
                }
            }
        }
        AttendanceProcessed::whereIn(
            'id',
            $request->attendance_ids
        )->update([
                    'attendance_status' => $request->attendance_status,
                    'remarks' => $request->remarks,
                    'updated_at' => now()
                ]);

        return response()->json([
            'status' => 200,
            'message' => 'Attendance statuses updated successfully'
        ]);
    }
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);
            $viewType = strtolower($request->input('view_type', 'daily'));

            // Parse Date and View Type (daily or monthly)
            $startDate = null;
            $endDate = null;
            $statsDate = null;

            if ($request->filled('month') && $request->filled('year')) {
                try {
                    $parsedDate = Carbon::create((int) $request->year, (int) $request->month, 1)->startOfDay();
                    $statsDate = $parsedDate;
                    if ($viewType === 'monthly') {
                        $startDate = $parsedDate->copy()->startOfMonth()->startOfDay();
                        $endDate = $parsedDate->copy()->endOfMonth()->endOfDay();
                    } else {
                        $startDate = $parsedDate->copy()->startOfDay();
                        $endDate = $parsedDate->copy()->endOfDay();
                    }
                } catch (\Exception $e) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Invalid month or year.'
                    ], 422);
                }
            } else if ($request->filled('from_date') && $request->filled('to_date')) {
                try {
                    $startDate = Carbon::parse($request->from_date)->startOfDay();
                    $endDate = Carbon::parse($request->to_date)->endOfDay();
                    $statsDate = $startDate;
                } catch (\Exception $e) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Invalid from_date or to_date format.'
                    ], 422);
                }
            } else {
                $dateInput = $request->input('date');
                $parsedDate = null;

                if ($dateInput) {
                    $formats = ['Y-m-d', 'm/d/Y', 'd/m/Y', 'd-m-Y', 'Y/m/d'];
                    foreach ($formats as $format) {
                        try {
                            $parsedDate = Carbon::createFromFormat($format, $dateInput)->startOfDay();
                            break;
                        } catch (\Exception $e) {
                            // continue
                        }
                    }
                    if (!$parsedDate) {
                        try {
                            $parsedDate = Carbon::parse($dateInput)->startOfDay();
                        } catch (\Exception $e) {
                            return response()->json([
                                'status' => 422,
                                'message' => 'Invalid date format.'
                            ], 422);
                        }
                    }
                } else {
                    $parsedDate = Carbon::today();
                }

                $statsDate = $parsedDate;

                if ($viewType === 'monthly') {
                    $startDate = $parsedDate->copy()->startOfMonth()->startOfDay();
                    $endDate = $parsedDate->copy()->endOfMonth()->endOfDay();
                } else {
                    $startDate = $parsedDate->copy()->startOfDay();
                    $endDate = $parsedDate->copy()->endOfDay();
                }
            }

            // Employee Status Filters (Active / Inactive / All Statuses)
            $employeeStatusInput = $request->input('employee_status') ?? $request->input('is_active') ?? $request->input('status');
            $filterActive = true; // default is active only
            $applyActiveFilter = true;

            if ($employeeStatusInput !== null) {
                $statusStr = strtolower((string) $employeeStatusInput);
                if ($statusStr === 'inactive' || $statusStr === '0' || $statusStr === 'false') {
                    $filterActive = false;
                    $applyActiveFilter = true;
                } elseif ($statusStr === 'all' || $statusStr === 'all statuses') {
                    $applyActiveFilter = false;
                } elseif ($statusStr === 'active' || $statusStr === '1' || $statusStr === 'true') {
                    $filterActive = true;
                    $applyActiveFilter = true;
                }
            }

            // Attendance Status Filter mapping
            $attendanceStatusInput = null;
            if ($request->filled('attendance_status')) {
                $attendanceStatusInput = $request->attendance_status;
            } elseif ($request->filled('status')) {
                $statusStr = strtolower((string) $request->status);
                $validAttendanceStatuses = ['present', 'absent', 'half_day', 'leave', 'rest_day'];
                if (in_array($statusStr, $validAttendanceStatuses)) {
                    $attendanceStatusInput = $statusStr;
                }
            }

            // Build Employee Query to calculate total_employees card stat
            $employeeQuery = \App\Models\Employee::query();
            if ($applyActiveFilter) {
                $employeeQuery->where('is_active', $filterActive);
            }
            if ($request->filled('site_id')) {
                $employeeQuery->where('site_id', $request->site_id);
            }
            if ($request->filled('department_id')) {
                $employeeQuery->where('department_id', $request->department_id);
            }
            if ($request->filled('search')) {
                $search = $request->search;
                $employeeQuery->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('employee_code', 'LIKE', "%{$search}%");
                });
            }
            $totalEmployeesCount = $employeeQuery->count();

            // Build Stats Query for other cards (Present, Absent, Half Day, Leaves)
            $statsQuery = AttendanceProcessed::query();
            if ($viewType === 'monthly') {
                $statsQuery->whereMonth('date', $statsDate->month)
                    ->whereYear('date', $statsDate->year);
            } else {
                $statsQuery->whereDate('date', $statsDate->format('Y-m-d'));
            }

            $statsQuery->whereHas('employee', function ($q) use ($request, $statsDate, $viewType, $applyActiveFilter, $filterActive, $attendanceStatusInput) {
                if ($applyActiveFilter) {
                    $q->where('is_active', $filterActive);
                }
                if ($request->filled('site_id')) {
                    $q->where('site_id', $request->site_id);
                }
                if ($request->filled('department_id')) {
                    $q->where('department_id', $request->department_id);
                }
                if ($request->filled('search')) {
                    $search = $request->search;
                    $q->where(function ($sq) use ($search) {
                        $sq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('employee_code', 'LIKE', "%{$search}%");
                    });
                }
                if ($attendanceStatusInput) {
                    $q->whereHas('attendanceProcesseds', function ($aq) use ($statsDate, $attendanceStatusInput, $viewType) {
                        if ($viewType === 'monthly') {
                            $aq->whereMonth('date', $statsDate->month)
                                ->whereYear('date', $statsDate->year);
                        } else {
                            $aq->whereDate('date', $statsDate->format('Y-m-d'));
                        }
                        $aq->where('attendance_status', $attendanceStatusInput);
                    });
                }
            });

            $statusCounts = $statsQuery->select('attendance_status', DB::raw('count(*) as count'))
                ->groupBy('attendance_status')
                ->pluck('count', 'attendance_status')
                ->toArray();

            $presentCount = $statusCounts['present'] ?? 0;
            $absentCount = $statusCounts['absent'] ?? 0;
            $halfDayCount = $statusCounts['half_day'] ?? 0;
            $leaveCount = $statusCounts['leave'] ?? 0;

            if ($viewType === 'monthly') {
                $month = $statsDate->month;
                $year = $statsDate->year;
                $daysInMonth = Carbon::create($year, $month)->daysInMonth;

                // Count holidays for the month
                $generalHolidays = Holiday::whereMonth('holiday_date', $month)
                    ->whereYear('holiday_date', $year)
                    ->where('is_active', true)
                    ->whereNull('site_id')
                    ->count();

                $siteHolidays = Holiday::whereMonth('holiday_date', $month)
                    ->whereYear('holiday_date', $year)
                    ->where('is_active', true)
                    ->whereNotNull('site_id')
                    ->selectRaw('site_id, COUNT(*) as count')
                    ->groupBy('site_id')
                    ->pluck('count', 'site_id')
                    ->toArray();

                // Build Employee query
                $employeeQuery = \App\Models\Employee::with(['site', 'department']);
                if ($applyActiveFilter) {
                    $employeeQuery->where('is_active', $filterActive);
                }

                if ($request->filled('site_id')) {
                    $employeeQuery->where('site_id', $request->site_id);
                }
                if ($request->filled('department_id')) {
                    $employeeQuery->where('department_id', $request->department_id);
                }
                if ($request->filled('search')) {
                    $search = $request->search;
                    $employeeQuery->where(function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('employee_code', 'LIKE', "%{$search}%");
                    });
                }
                if ($attendanceStatusInput) {
                    $employeeQuery->whereHas('attendanceProcesseds', function ($q) use ($month, $year, $attendanceStatusInput) {
                        $q->whereMonth('date', $month)
                            ->whereYear('date', $year)
                            ->where('attendance_status', $attendanceStatusInput);
                    });
                }

                $employees = $employeeQuery->orderBy('name')->paginate($limit);
                $employeeIds = $employees->pluck('id')->toArray();

                // Fetch attendance counts for these employees
                $attendanceCounts = AttendanceProcessed::whereIn('employee_id', $employeeIds)
                    ->whereMonth('date', $month)
                    ->whereYear('date', $year)
                    ->selectRaw('employee_id,
                        SUM(CASE WHEN attendance_status = "present" THEN 1 ELSE 0 END) as present_days,
                        SUM(CASE WHEN attendance_status = "absent" THEN 1 ELSE 0 END) as absent_days,
                        SUM(CASE WHEN attendance_status = "half_day" THEN 1 ELSE 0 END) as half_days,
                        SUM(CASE WHEN attendance_status = "leave" THEN 1 ELSE 0 END) as leave_days,
                        SUM(CASE WHEN attendance_status = "rest_day" THEN 1 ELSE 0 END) as rest_days
                    ')
                    ->groupBy('employee_id')
                    ->get()
                    ->keyBy('employee_id');

                // Fetch approved leaves
                $leaves = Leave::whereIn('employee_id', $employeeIds)
                    ->where('status', 'approved')
                    ->where(function ($q) use ($month, $year) {
                        $q->where(function ($q2) use ($month, $year) {
                            $q2->whereMonth('from_date', $month)->whereYear('from_date', $year);
                        })->orWhere(function ($q2) use ($month, $year) {
                            $q2->whereMonth('to_date', $month)->whereYear('to_date', $year);
                        });
                    })
                    ->with('leaveType')
                    ->get();

                $leaveSummary = [];
                $monthStart = Carbon::create($year, $month, 1)->startOfDay();
                $monthEnd = $monthStart->copy()->endOfMonth();

                foreach ($leaves as $leave) {
                    $empId = $leave->employee_id;
                    if (!isset($leaveSummary[$empId])) {
                        $leaveSummary[$empId] = ['paid' => 0, 'unpaid' => 0];
                    }

                    $from = Carbon::parse($leave->from_date)->max($monthStart);
                    $to = Carbon::parse($leave->to_date)->min($monthEnd);
                    $days = $from->diffInDays($to) + 1;

                    $category = optional($leave->leaveType)->leave_category ?? 'unpaid';
                    if ($category === 'paid') {
                        $leaveSummary[$empId]['paid'] += $days;
                    } else {
                        $leaveSummary[$empId]['unpaid'] += $days;
                    }
                }

                $data = collect($employees->items())->map(function ($employee) use ($attendanceCounts, $leaveSummary, $generalHolidays, $siteHolidays, $daysInMonth) {
                    $att = $attendanceCounts->get($employee->id);
                    $empLeave = $leaveSummary[$employee->id] ?? ['paid' => 0, 'unpaid' => 0];

                    $present = $att ? (int) $att->present_days : 0;
                    $absent = $att ? (int) $att->absent_days : 0;
                    $halfDay = $att ? (int) $att->half_days : 0;
                    $restDay = $att ? (int) $att->rest_days : 0;
                    $restDaysSetting = (int) $employee->rest_days; // Uses accessor which automatically gets from activePayroll
                    $paidRestDays = min($restDay, $restDaysSetting);
                    $leave = $empLeave['paid'] + $empLeave['unpaid'];

                    $holidays = $generalHolidays + ($siteHolidays[$employee->site_id] ?? 0);

                    // Payable days calculation matching payroll logic:
                    // Payable Days = Present + (Half Day * 0.5) + Rest Day + Paid Leave + Holiday
                    $payableDays = $present + ($halfDay * 0.5) + $paidRestDays + $empLeave['paid'] + $holidays;

                    return [
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->name,
                        'employee_code' => $employee->employee_code,
                        'site_name' => $employee->site ? $employee->site->site_name : null,
                        'total_days' => $daysInMonth,
                        'present' => $present,
                        'absent' => $absent,
                        'half_day' => $halfDay,
                        'rest_day' => $restDay,
                        'leave' => $leave,
                        'payable_days' => $payableDays,
                    ];
                });

                return response()->json([
                    'status' => 200,
                    'message' => 'Attendance fetched successfully',
                    'summary' => [
                        'total_employees' => $totalEmployeesCount,
                        'present' => $presentCount,
                        'absent' => $absentCount,
                        'half_day' => $halfDayCount,
                        'leaves' => $leaveCount,
                    ],
                    'data' => $data,
                    'pagination' => [
                        'current_page' => $employees->currentPage(),
                        'last_page' => $employees->lastPage(),
                        'per_page' => $employees->perPage(),
                        'total' => $employees->total(),
                        'from' => $employees->firstItem(),
                        'to' => $employees->lastItem(),
                    ]
                ]);
            }

            // Main Attendance Query
            $query = AttendanceProcessed::with(['employee.site', 'shift'])
                ->whereBetween('date', [
                    $startDate->format('Y-m-d'),
                    $endDate->format('Y-m-d')
                ])
                ->whereHas('employee', function ($q) use ($request, $applyActiveFilter, $filterActive) {
                    if ($applyActiveFilter) {
                        $q->where('is_active', $filterActive);
                    }
                    if ($request->filled('site_id')) {
                        $q->where('site_id', $request->site_id);
                    }
                    if ($request->filled('department_id')) {
                        $q->where('department_id', $request->department_id);
                    }
                    if ($request->filled('search')) {
                        $search = $request->search;
                        $q->where(function ($sq) use ($search) {
                            $sq->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('employee_code', 'LIKE', "%{$search}%");
                        });
                    }
                });

            if ($attendanceStatusInput) {
                $query->where('attendance_status', $attendanceStatusInput);
            }

            $attendance = $query->latest('date')->paginate($limit);

            $data = collect($attendance->items())->map(function ($item) {
                return [
                    'id' => $item->id,
                    'employee_id' => $item->employee_id,
                    'shift_id' => $item->shift_id,
                    'employee_name' => $item->employee->name ?? null,
                    'employee_code' => $item->employee->employee_code ?? null,
                    'site_name' => $item->employee->site->site_name ?? null,
                    'shift_name' => $item->shift->shift_name ?? null,
                    'date' => $item->date
                        ? \Carbon\Carbon::parse($item->date)->format('d M Y')
                        : null,
                    'check_in' => $item->check_in
                        ? \Carbon\Carbon::parse($item->check_in)->format('H:i')
                        : null,
                    'check_out' => $item->check_out
                        ? \Carbon\Carbon::parse($item->check_out)->format('H:i')
                        : null,
                    'working_hours' => $item->working_hours,
                    'late_minutes' => $item->late_minutes,
                    'early_exit_minutes' => $item->early_exit_minutes,
                    'attendance_status' => $item->attendance_status,
                    'attendance_status_label' => $item->attendance_status
                        ? ucwords(str_replace('_', ' ', $item->attendance_status))
                        : null,
                    'remarks' => $item->remarks,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];
            });

            return response()->json([
                'status' => 200,
                'message' => 'Attendance fetched successfully',
                'summary' => [
                    'total_employees' => $totalEmployeesCount,
                    'present' => $presentCount,
                    'absent' => $absentCount,
                    'half_day' => $halfDayCount,
                    'leaves' => $leaveCount,
                ],
                'data' => $data,
                'pagination' => [
                    'current_page' => $attendance->currentPage(),
                    'last_page' => $attendance->lastPage(),
                    'per_page' => $attendance->perPage(),
                    'total' => $attendance->total(),
                    'from' => $attendance->firstItem(),
                    'to' => $attendance->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }
}
