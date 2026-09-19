<?php

namespace App\Http\Controllers\Api\Admin;
use App\Exports\AttendanceRegisterExport;
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
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Validators\ValidationException as ExcelValidationExceptio;

class AttendanceController extends Controller
{
    /**
     * A full working day for the Attendance Register's overtime column. Hours
     * beyond this on a single day are overtime.
     */
    private const STANDARD_WORKING_HOURS = 8.0;

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
                $recordByAttendanceId = null;
                if ($date) {
                    $recordByAttendanceId = AttendanceProcessed::where('id', $id)
                        ->whereDate('date', $date)
                        ->first();
                } else {
                    $recordByAttendanceId = AttendanceProcessed::find($id);
                }

                $employee = \App\Models\Employee::find($id);

                if ($recordByAttendanceId && $employee) {
                    // Collision check
                    if ($recordByAttendanceId->employee_id != $employee->id) {
                        // Collision! Prioritize specific date context record
                        $attendance = $recordByAttendanceId;
                    } else {
                        // No collision
                        $attendance = $recordByAttendanceId;
                    }
                } else {
                    // No collision
                    if ($recordByAttendanceId) {
                        $attendance = $recordByAttendanceId;
                    } else if ($employee && $date) {
                        // Fall back to Employee ID lookup
                        $attendance = AttendanceProcessed::where('employee_id', $employee->id)
                            ->whereDate('date', $date)
                            ->first();
                    }
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

            // Both times are parsed against the one attendance date, so a night
            // shift's check-out lands before its own check-in until it is rolled
            // onto the next day. Same rule the import applies.
            if ($checkIn && $checkOut) {

                if ($checkOut->equalTo($checkIn)) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Check-out cannot equal check-in'
                    ], 422);
                }

                $checkOut = \App\Services\ShiftRosterResolver::resolveCheckOut($checkIn, $checkOut);

                $spanHours = $checkIn->diffInMinutes($checkOut) / 60;

                if ($spanHours > \App\Services\ShiftRosterResolver::MAX_ATTENDANCE_SPAN_HOURS) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Check-in to check-out spans ' . round($spanHours, 2)
                            . ' hours, which exceeds the '
                            . \App\Services\ShiftRosterResolver::MAX_ATTENDANCE_SPAN_HOURS . ' hour limit'
                    ], 422);
                }
            }

            $status = strtolower($data['attendance_status']);
            $dbStatus = $status;
            if ($status === 'exception') {
                $dbStatus = 'absent';
            }

            // A day marked leave/rest_day must be backed by a Leave row so the
            // register and payroll read one source; a day marked worked/absent
            // must not silently bury a leave someone filed by hand.
            // 'leave' + a Compensatory Rest type folds to a rest day first.
            $leaveTypeId = $request->filled('leave_type_id') ? (int) $request->input('leave_type_id') : null;
            [$dbStatus, $leaveTypeId] = \App\Services\AttendanceLeaveSync::normalize($dbStatus, $leaveTypeId);

            // A correction cannot be used to slip past the monthly rest-day cap
            // either. The row being corrected is excluded so a day already
            // marked rest_day can still be re-saved.
            if ($dbStatus === 'rest_day') {
                $capMessage = \App\Services\LeaveBalanceService::restDayCapMessage(
                    $employee->id,
                    $attendanceDate,
                    $attendance ? [$attendance->id] : []
                );

                if ($capMessage) {
                    return response()->json([
                        'status' => 422,
                        'message' => $capMessage
                    ], 422);
                }
            }

            if (in_array($dbStatus, \App\Services\AttendanceLeaveSync::LEAVE_STATUSES, true)) {
                try {
                    $syncLeaveType = \App\Services\AttendanceLeaveSync::resolveLeaveType($dbStatus, $leaveTypeId);
                } catch (\InvalidArgumentException $e) {
                    return response()->json(['status' => 422, 'message' => $e->getMessage()], 422);
                }

                $blockMessage = \App\Services\AttendanceLeaveSync::blockMessage(
                    $employee->id,
                    $attendanceDate,
                    $syncLeaveType
                );

                if ($blockMessage) {
                    return response()->json(['status' => 422, 'message' => $blockMessage], 422);
                }
            } else {
                $manualLeave = \App\Services\AttendanceLeaveSync::manualLeaveOn($employee->id, $attendanceDate);

                if ($manualLeave) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'An approved leave in Leave Management covers ' . $attendanceDate
                            . '. Cancel it there before changing this day.',
                    ], 422);
                }
            }

            // Resolve shift
            $shift = $attendance ? $attendance->shift : null;
            $shiftId = $attendance ? $attendance->shift_id : null;
            if (!$shift) {
                $shiftId = $employee->shift_id;
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

            // Only overwrite when supplied, so a correction that omits it does not
            // wipe the place of work already recorded for the day.
            $placeOfWork = $request->filled('place_of_work') ? $request->input('place_of_work') : null;

            $resultAttendance = null;
            DB::transaction(function () use (&$attendance, $employee, $shiftId, $attendanceDate, $checkIn, $checkOut, $workingHours, $lateMinutes, $earlyExitMinutes, $dbStatus, $leaveTypeId, $remarks, $placeOfWork, &$resultAttendance) {
                if ($attendance) {
                    $payload = [
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'working_hours' => $workingHours,
                        'late_minutes' => $lateMinutes,
                        'early_exit_minutes' => $earlyExitMinutes,
                        'attendance_status' => $dbStatus,
                        'remarks' => $remarks,
                    ];

                    if ($placeOfWork) {
                        $payload['place_of_work'] = $placeOfWork;
                    }

                    $attendance->update($payload);
                    $attendance->refresh();
                    $resultAttendance = $attendance;
                } else {
                    $resultAttendance = AttendanceProcessed::create([
                        'employee_id' => $employee->id,
                        'shift_id' => $shiftId,
                        // A new day starts at the worker's standing assignment
                        // so Form D column 4 is never blank; a later correction
                        // overrides it with where they actually were.
                        'place_of_work' => $placeOfWork ?: $employee->place_of_employment,
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

                // Keep the backing Leave row in step with the day.
                if (in_array($dbStatus, \App\Services\AttendanceLeaveSync::LEAVE_STATUSES, true)) {
                    \App\Services\AttendanceLeaveSync::sync(
                        $employee->id,
                        $attendanceDate,
                        $dbStatus,
                        $leaveTypeId
                    );
                } else {
                    \App\Services\AttendanceLeaveSync::clear($employee->id, $attendanceDate);
                }
            });

            return response()->json([
                'status' => 200,
                'message' => 'Attendance updated successfully',
                'data' => [
                    'id' => $resultAttendance->id,
                    'employee_id' => $resultAttendance->employee_id,
                    'date' => $resultAttendance->date,
                    'check_in' => $resultAttendance->check_in ? Carbon::parse($resultAttendance->check_in)->toDateTimeString() : null,
                    'check_out' => $resultAttendance->check_out ? Carbon::parse($resultAttendance->check_out)->toDateTimeString() : null,
                    'working_hours' => (float) $resultAttendance->working_hours,
                    'attendance_status' => $resultAttendance->attendance_status,
                    'place_of_work' => $resultAttendance->place_of_work,
                    'remarks' => $resultAttendance->remarks,
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
                        'leave_type_id' => $leave->leave_type_id,
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

                $status = null;
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

                // Leave type comes from the backing `leaves` row keyed by date.
                $dayLeave = $leaveDays[$dateString] ?? null;

                $history[] = [
                    'date' => $dateString,
                    'formatted_date' => $carbonDate->format('d/m/Y'),
                    'status' => $status,
                    'leave_type_id' => $dayLeave['leave_type_id'] ?? null,
                    'leave_type_name' => $dayLeave['leave_type'] ?? null,
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


    /**
     * Attendance Register (Form D).
     *
     * One row per employee for a calendar month, with an IN and an OUT value for
     * each day 1..31 — the layout the printed register uses.
     *
     * Column 9 (OT hours) is derived, not stored — there is no overtime column on
     * attendance_processeds. Overtime is the hours worked beyond the length of the
     * shift the employee was rostered on that day, so a 09:00-11:00 shift worked
     * until 12:00 yields one hour. STANDARD_WORKING_HOURS is only the fallback for
     * days where no shift can be resolved at all.
     *
     * The establishment header (name / owner / LIN) and column 11 (signature of
     * register keeper) are still not emitted: there is no company settings record
     * to read the header from.
     */
    public function attendanceRegister(Request $request)
    {
        try {
            $result = $this->buildAttendanceRegister($request);

            if (isset($result['error'])) {
                return response()->json([
                    'status' => 422,
                    'message' => $result['error']
                ], 422);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Attendance register fetched successfully',
                'data' => $result['data'],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Form D as a downloadable spreadsheet.
     *
     * Same rows as attendanceRegister() and the same filters, but never
     * paginated: a register printed for the inspector has to carry every worker
     * on it, so limit is forced to 0 whatever the caller asked for.
     */
    public function exportAttendanceRegister(Request $request)
    {
        try {
            $request->merge(['limit' => 0]);

            $result = $this->buildAttendanceRegister($request);

            if (isset($result['error'])) {
                return response()->json([
                    'status' => 422,
                    'message' => $result['error']
                ], 422);
            }

            $data = $result['data'];

            if (empty($data['rows'])) {
                return response()->json([
                    'status' => 422,
                    'message' => "The attendance register for {$data['month']} has no rows to export."
                ], 422);
            }

            $filename = 'attendance-register-' . sprintf('%04d-%02d', $data['year'], $data['month_num']) . '.xlsx';

            return Excel::download(new AttendanceRegisterExport($data), $filename);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Builds the Form D grid shared by the JSON endpoint and the export.
     *
     * Returns ['data' => ...] on success, or ['error' => message] for the two
     * caller mistakes (unparseable date, month out of range) so each entry
     * point can shape its own 422.
     */
    private function buildAttendanceRegister(Request $request): array
    {
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        if ($request->filled('date')) {
            try {
                $parsed = Carbon::parse($request->date);
                $month = $parsed->month;
                $year = $parsed->year;
            } catch (\Exception $e) {
                return ['error' => 'Invalid date format.'];
            }
        }

        if ($month < 1 || $month > 12) {
            return ['error' => 'Invalid month.'];
        }

        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth()->endOfDay();
        $daysInMonth = $startDate->daysInMonth;

        $employeeQuery = \App\Models\Employee::with(['relay', 'site', 'department', 'designation'])
            ->orderBy('employee_code')
            ->orderBy('id');

        // Register rows are the establishment's own workers, so the same roles
        // the attendance listing hides are hidden here.
        $excludedRoles = ['Super Admin', 'CEO'];
        $employeeQuery->whereDoesntHave('roleUser.user.roles', function ($q) use ($excludedRoles) {
            $q->whereIn('name', $excludedRoles);
        });

        if ($request->filled('site_id')) {
            $employeeQuery->where('site_id', $request->site_id);
        }

        if ($request->filled('department_id')) {
            $employeeQuery->where('department_id', $request->department_id);
        }

        if ($request->filled('relay_id')) {
            $employeeQuery->where('relay_id', $request->relay_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $employeeQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('surname', 'like', "%{$search}%")
                    ->orWhere('employee_code', 'like', "%{$search}%");
            });
        }

        // Default to the people on the register during that month: anyone who
        // had not yet joined, or who had already left, does not get a row.
        $employeeStatus = strtolower((string) $request->input('employee_status', 'active'));
        if ($employeeStatus !== 'all') {
            $employeeQuery->whereDate('joining_date', '<=', $endDate->format('Y-m-d'))
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('date_of_exit')
                        ->orWhereDate('date_of_exit', '>=', $startDate->format('Y-m-d'));
                });
        }

        $limit = (int) $request->input('limit', 25);
        $employees = $limit > 0
            ? $employeeQuery->paginate($limit)
            : $employeeQuery->get();

        $employeeList = collect($limit > 0 ? $employees->items() : $employees);
        $employeeIds = $employeeList->pluck('id');

        $records = AttendanceProcessed::whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get()
            ->groupBy('employee_id');

        // Overtime is measured against the day's own rostered shift, so the
        // shift has to be resolved even though it is not part of the response.
        // attendance_processeds.shift_id is mostly NULL, so the roster is the
        // real source: the same override -> relay mapping -> legacy assignment
        // precedence Employee::getShiftIdForDate() applies, preloaded here so a
        // 31-day grid does not run those lookups once per employee per day.
        $shiftHours = \App\Models\Shift::all()
            ->mapWithKeys(function ($shift) {
                return [$shift->id => $this->shiftScheduledHours($shift)];
            })
            ->filter();
        $rosterContext = $this->buildShiftRosterContext($employeeList, $startDate, $endDate);

        // Column 1 continues across pages so the printed register numbers run 1..n.
        $serial = $limit > 0
            ? (($employees->currentPage() - 1) * $employees->perPage()) + 1
            : 1;

        $rows = [];
        foreach ($limit > 0 ? $employees->items() : $employees as $employee) {
            $empRecords = ($records->get($employee->id) ?? collect())
                ->keyBy(function ($item) {
                    return (int) Carbon::parse($item->date)->day;
                });

            $days = [];
            $totalDays = 0.0;
            $totalOtHours = 0.0;
            $placesWorked = [];

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $record = $empRecords->get($day);
                $dateStr = $startDate->copy()->day($day)->format('Y-m-d');

                if (!$record) {
                    $days[$day] = [
                        'in' => null,
                        'out' => null,
                        'status' => null,
                        'place_of_work' => null,
                        'ot_hours' => null,
                    ];
                    continue;
                }

                if ($record->attendance_status === 'present') {
                    $totalDays += 1;
                } else if ($record->attendance_status === 'half_day') {
                    $totalDays += 0.5;
                }

                // Place of work is not captured when attendance is marked, so
                // it reads through from the worker's standing assignment the
                // way their name does. A value stored on the day - a correction
                // recording where they actually were - still wins.
                $dayPlace = $record->place_of_work ?: $employee->place_of_employment;

                if ($dayPlace) {
                    $placesWorked[$dayPlace] = true;
                }

                // Overtime is whatever was worked beyond the shift the employee
                // was rostered on that day — a 09:00-11:00 shift worked until
                // 12:00 is one hour of OT. What the row recorded wins over the
                // roster; the constant is only reached when no shift resolves.
                $shiftId = $record->shift_id
                    ?: $this->resolveRosterShiftId($employee, $dateStr, $rosterContext);
                $scheduledHours = $shiftId && isset($shiftHours[$shiftId])
                    ? $shiftHours[$shiftId]
                    : self::STANDARD_WORKING_HOURS;

                // Status-agnostic on purpose: overtime follows the hours logged,
                // so a day worked beyond a full shift counts even if it was a
                // rest day. Absent and leave days carry 0 hours and so score 0.
                $otHours = round(max(0, (float) $record->working_hours - $scheduledHours), 2);
                $totalOtHours += $otHours;

                $days[$day] = [
                    'in' => $record->check_in ? Carbon::parse($record->check_in)->format('H:i') : null,
                    'out' => $record->check_out ? Carbon::parse($record->check_out)->format('H:i') : null,
                    'status' => $record->attendance_status,
                    'place_of_work' => $dayPlace,
                    'ot_hours' => $otHours,
                ];
            }

            // Column 4 is a single cell, so a worker moved between locations
            // during the month shows every location they were recorded at.
            $places = array_keys($placesWorked);

            // A worker with no attendance at all this month still has a place:
            // column 4 falls back to the employee record so it is never blank.
            if (empty($places) && $employee->place_of_employment) {
                $places = [$employee->place_of_employment];
            }

            $rows[] = [
                'serial_no' => $serial++,
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->full_name,
                'relay' => optional($employee->relay)->name,
                'place_of_work' => count($places) === 1 ? $places[0] : null,
                'place_of_work_label' => implode(', ', array_map('ucfirst', $places)) ?: null,
                'days' => $days,
                'total_days' => $totalDays,
                'total_ot_hours' => round($totalOtHours, 2),
                // Column 10 is left for the register keeper to fill in by hand.
                'remarks' => null,
            ];
        }

        $data = [
            'month' => $startDate->format('F Y'),
            'month_num' => $month,
            'year' => $year,
            'days_in_month' => $daysInMonth,
            'rows' => $rows,
        ];

        if ($limit > 0) {
            $data['pagination'] = [
                'current_page' => $employees->currentPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
                'last_page' => $employees->lastPage(),
            ];
        }

        return ['data' => $data];
    }

    /**
     * Shift-roster resolution is shared with payroll overtime — see
     * ShiftRosterResolver. These stay as thin wrappers so the register code
     * below reads unchanged.
     */
    private function shiftScheduledHours($shift): ?float
    {
        return app(\App\Services\ShiftRosterResolver::class)->scheduledHours($shift);
    }

    private function buildShiftRosterContext($employees, Carbon $startDate, Carbon $endDate): array
    {
        return app(\App\Services\ShiftRosterResolver::class)->preload($employees, $startDate, $endDate);
    }

    private function resolveRosterShiftId($employee, string $dateStr, array $ctx)
    {
        return app(\App\Services\ShiftRosterResolver::class)->resolve($employee, $dateStr, $ctx);
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

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {

            $failures = [];

            foreach ($e->failures() as $failure) {

                $failures[] = [
                    'row' => $failure->row(),
                    'column' => $failure->attribute(),
                    'message' => implode(', ', $failure->errors()),
                    'value' => $failure->values()[$failure->attribute()] ?? null,
                ];
            }

            return response()->json([
                'status' => 422,
                'message' => 'Excel validation failed.',
                'errors' => $failures
            ], 422);

        } catch (ValidationException $e) {

            $errors = [];

            foreach ($e->errors() as $row => $messages) {

                $errors[] = [
                    'row' => str_replace('row_', '', $row),
                    'message' => $messages[0]
                ];
            }

            return response()->json([
                'status' => 422,
                'message' => 'Excel validation failed.',
                'errors' => $errors
            ], 422);

        } catch (\Throwable $e) {

            return response()->json([
                'status' => 422,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
    public function show(int $id)
    {
        try {
            $employee = AttendanceProcessed::with(['employee.site', 'employee.relay', 'shift'])->find($id);
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
            'leave_type_id' => 'required_if:attendance_status,leave|nullable|integer|exists:leave_types,id',
            'remarks' => 'nullable|string'
        ]);

        $attendance = AttendanceProcessed::find($id);

        if (!$attendance) {
            return response()->json([
                'status' => 404,
                'message' => 'Attendance not found'
            ]);
        }

        $attendanceDate = Carbon::parse($attendance->date)->format('Y-m-d');
        $leaveTypeId = $request->filled('leave_type_id') ? (int) $request->input('leave_type_id') : null;
        // 'leave' + a Compensatory Rest type folds to a rest day.
        [$status, $leaveTypeId] = \App\Services\AttendanceLeaveSync::normalize($request->attendance_status, $leaveTypeId);

        // A month gives only so many rest days; past that the next one waits for
        // the following month. Excludes this row so re-saving a day that is
        // already a rest day is not read as a new one.
        if ($status === 'rest_day') {
            $capMessage = \App\Services\LeaveBalanceService::restDayCapMessage(
                $attendance->employee_id,
                $attendanceDate,
                [$attendance->id]
            );

            if ($capMessage) {
                return response()->json([
                    'status' => 422,
                    'message' => $capMessage
                ], 422);
            }
        }

        // Keep attendance and Leave Management in step: a leave/rest_day needs a
        // backing Leave row (on the block the caller named, or Compensatory Rest
        // for a rest day); any other status must not overwrite a hand-filed leave.
        if (in_array($status, \App\Services\AttendanceLeaveSync::LEAVE_STATUSES, true)) {
            try {
                $syncLeaveType = \App\Services\AttendanceLeaveSync::resolveLeaveType($status, $leaveTypeId);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['status' => 422, 'message' => $e->getMessage()], 422);
            }

            $blockMessage = \App\Services\AttendanceLeaveSync::blockMessage(
                $attendance->employee_id,
                $attendanceDate,
                $syncLeaveType
            );

            if ($blockMessage) {
                return response()->json(['status' => 422, 'message' => $blockMessage], 422);
            }
        } else {
            $manualLeave = \App\Services\AttendanceLeaveSync::manualLeaveOn($attendance->employee_id, $attendanceDate);

            if ($manualLeave) {
                return response()->json([
                    'status' => 422,
                    'message' => 'An approved leave in Leave Management covers ' . $attendanceDate
                        . '. Cancel it there before changing this day.',
                ], 422);
            }
        }

        DB::transaction(function () use ($attendance, $request, $status, $attendanceDate, $leaveTypeId) {
            $attendance->update([
                'attendance_status' => $status,
                'remarks' => $request->remarks
            ]);

            if (in_array($status, \App\Services\AttendanceLeaveSync::LEAVE_STATUSES, true)) {
                \App\Services\AttendanceLeaveSync::sync($attendance->employee_id, $attendanceDate, $status, $leaveTypeId);
            } else {
                \App\Services\AttendanceLeaveSync::clear($attendance->employee_id, $attendanceDate);
            }
        });

        return response()->json([
            'status' => 200,
            'message' => 'Attendance status updated successfully',

        ]);
    }
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'attendance_ids' => 'required|array|min:1',
            'attendance_ids.*' => 'integer',
            'attendance_status' => 'required|in:present,absent,half_day,leave,rest_day',
            'leave_type_id' => 'required_if:attendance_status,leave|nullable|integer|exists:leave_types,id',
            'remarks' => 'nullable|string',
            'date' => 'nullable|date_format:Y-m-d',
            'from_date' => 'nullable|date_format:Y-m-d',
        ]);

        $date = $request->input('date') ?: $request->input('from_date') ?: today()->format('Y-m-d');
        $dateStr = \Carbon\Carbon::parse($date)->format('Y-m-d');
        $leaveTypeId = $request->filled('leave_type_id') ? (int) $request->input('leave_type_id') : null;
        // 'leave' + a Compensatory Rest type folds to a rest day for the batch.
        [$status, $leaveTypeId] = \App\Services\AttendanceLeaveSync::normalize($request->attendance_status, $leaveTypeId);
        $isLeaveStatus = in_array($status, \App\Services\AttendanceLeaveSync::LEAVE_STATUSES, true);

        // Same block for every row in the batch; resolve it once.
        if ($isLeaveStatus) {
            try {
                \App\Services\AttendanceLeaveSync::resolveLeaveType($status, $leaveTypeId);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['status' => 422, 'message' => $e->getMessage()], 422);
            }
        }

        try {
            $resolvedAttendanceIds = [];

            DB::transaction(function () use ($request, $dateStr, $status, $leaveTypeId, $isLeaveStatus, &$resolvedAttendanceIds) {
                foreach ($request->attendance_ids as $id) {
                    $recordByAttendanceId = AttendanceProcessed::where('id', $id)
                        ->whereDate('date', $dateStr)
                        ->first();

                    $employee = \App\Models\Employee::find($id);

                    $record = null;

                    if ($recordByAttendanceId && $employee) {
                        // Collision check
                        if ($recordByAttendanceId->employee_id != $employee->id) {
                            // Collision! Prioritize finding records by specific date context
                            $record = $recordByAttendanceId;
                        } else {
                            // No collision
                            $record = $recordByAttendanceId;
                        }
                    } else {
                        // No collision
                        if ($recordByAttendanceId) {
                            $record = $recordByAttendanceId;
                        } else if ($employee) {
                            // Fall back to Employee ID lookup
                            $record = AttendanceProcessed::firstOrCreate([
                                'employee_id' => $employee->id,
                                'date' => $dateStr
                            ], [
                                'shift_id' => $employee->shift_id,
                                'place_of_work' => $employee->place_of_employment,
                                'attendance_status' => 'absent',
                                'working_hours' => 0.00,
                                'late_minutes' => 0,
                                'early_exit_minutes' => 0,
                            ]);
                        }
                    }

                    if ($record) {
                        $resolvedAttendanceIds[] = $record->id;
                    } else {
                        throw new \Exception("The selected ID {$id} is invalid.");
                    }
                }

                // Same monthly rest-day cap as the single update, checked per
                // employee. Days already marked rest_day are skipped, and days
                // accepted earlier in this same batch are carried forward so one
                // request cannot push an employee past the cap.
                if ($status === 'rest_day') {
                    $records = AttendanceProcessed::with('employee')
                        ->whereIn('id', $resolvedAttendanceIds)
                        ->get();

                    $pendingByEmployee = [];

                    foreach ($records as $record) {
                        if ($record->attendance_status === 'rest_day') {
                            continue;
                        }

                        $capMessage = \App\Services\LeaveBalanceService::restDayCapMessage(
                            $record->employee_id,
                            $dateStr,
                            [$record->id],
                            $pendingByEmployee[$record->employee_id] ?? 0
                        );

                        if ($capMessage) {
                            $code = optional($record->employee)->employee_code;

                            throw new \Exception($code ? "{$code}: {$capMessage}" : $capMessage);
                        }

                        $pendingByEmployee[$record->employee_id] =
                            ($pendingByEmployee[$record->employee_id] ?? 0) + 1;
                    }
                }

                $records = AttendanceProcessed::with('employee')
                    ->whereIn('id', $resolvedAttendanceIds)
                    ->get();

                foreach ($records as $record) {
                    $recordDate = \Carbon\Carbon::parse($record->date)->format('Y-m-d');
                    $code = optional($record->employee)->employee_code;

                    if ($isLeaveStatus) {
                        // Quota / cap / clash gates — the same ones the Leave
                        // apply form enforces, so a leave entered from attendance
                        // cannot push a block past its entitlement.
                        $syncType = \App\Services\AttendanceLeaveSync::resolveLeaveType($status, $leaveTypeId);
                        $blockMessage = \App\Services\AttendanceLeaveSync::blockMessage(
                            $record->employee_id,
                            $recordDate,
                            $syncType
                        );

                        if ($blockMessage) {
                            throw new \Exception($code ? "{$code}: {$blockMessage}" : $blockMessage);
                        }
                    } else {
                        // A worked/absent status must not silently bury a leave
                        // someone filed by hand in Leave Management.
                        $manualLeave = \App\Services\AttendanceLeaveSync::manualLeaveOn($record->employee_id, $recordDate);

                        if ($manualLeave) {
                            throw new \Exception(
                                ($code ? "{$code}: " : '')
                                . "An approved leave in Leave Management covers {$recordDate}. Cancel it there first."
                            );
                        }
                    }
                }

                AttendanceProcessed::whereIn('id', $resolvedAttendanceIds)->update([
                    'attendance_status' => $status,
                    'remarks' => $request->remarks,
                    'updated_at' => now()
                ]);

                // Keep each day's backing Leave row in step with the new status.
                foreach ($records as $record) {
                    $recordDate = \Carbon\Carbon::parse($record->date)->format('Y-m-d');

                    if ($isLeaveStatus) {
                        \App\Services\AttendanceLeaveSync::sync($record->employee_id, $recordDate, $status, $leaveTypeId);
                    } else {
                        \App\Services\AttendanceLeaveSync::clear($record->employee_id, $recordDate);
                    }
                }
            });

            return response()->json([
                'status' => 200,
                'message' => 'Attendance status updated successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 422,
                'message' => $th->getMessage()
            ], 422);
        }
    }
    public function index_1122(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
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

                // Paid holiday days per employee. Counted as distinct dates,
                // so a general and a site holiday on the same day - or plain
                // duplicate rows - are one day, and a holiday landing on a
                // weekly off or any other already-paid day adds nothing.
                $holidayDays = \App\Services\HolidayService::monthlyHolidayDays($employeeIds, $month, $year);

                $data = collect($employees->items())->map(function ($employee) use ($attendanceCounts, $leaveSummary, $holidayDays, $daysInMonth) {
                    $att = $attendanceCounts->get($employee->id);
                    $empLeave = $leaveSummary[$employee->id] ?? ['paid' => 0, 'unpaid' => 0];

                    $present = $att ? (int) $att->present_days : 0;
                    $absent = $att ? (int) $att->absent_days : 0;
                    $halfDay = $att ? (int) $att->half_days : 0;
                    $restDay = $att ? (int) $att->rest_days : 0;
                    $restDaysSetting = \App\Services\LeaveBalanceService::monthlyPaidRestDays();
                    $paidRestDays = min($restDay, $restDaysSetting);
                    $leave = $empLeave['paid'] + $empLeave['unpaid'];

                    $holidays = $holidayDays[$employee->id] ?? 0;
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
                        'paidRestDays' => $paidRestDays,
                        'empleave' => $empLeave['paid'],
                        'holidays' => $holidays,
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
    public function index_NewOld(Request $request)
    {

        try {
            $limit = $request->input('limit', null);
            ////  $viewType = strtolower($request->input('view_type', 'daily'));

            // Parse Date and View Type (daily or monthly)
            $startDate = null;
            $endDate = null;
            $statsDate = null;


            $viewType = strtolower($request->input('view_type', 'daily'));

            /*
            |--------------------------------------------------------------------------
            | CASE 1: from_date only (IMPORTANT FIX)
            |--------------------------------------------------------------------------
            */
            if ($viewType === 'daily' && $request->filled('from_date')) {
                try {
                    $parsedDate = Carbon::parse($request->from_date)->startOfDay();
                    $statsDate = $parsedDate;
                    $startDate = $parsedDate->copy()->startOfDay();
                    if ($request->filled('to_date')) {
                        $endDate = Carbon::parse($request->to_date)->endOfDay();
                    } else {
                        $endDate = $parsedDate->copy()->endOfDay();
                    }
                } catch (\Exception $e) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Invalid from_date format.'
                    ], 422);
                }
            } else if ($request->filled('from_date') && !$request->filled('to_date')) {

                try {
                    $statsDate = Carbon::parse($request->from_date);

                    if ($viewType === 'monthly') {
                        $startDate = $statsDate->copy()->startOfMonth()->startOfDay();
                        $endDate = $statsDate->copy()->endOfMonth()->endOfDay();
                    } else {
                        $startDate = $statsDate->copy()->startOfDay();
                        $endDate = $statsDate->copy()->endOfDay();
                    }

                } catch (\Exception $e) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Invalid from_date format.'
                    ], 422);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | CASE 2: from_date + to_date
            |--------------------------------------------------------------------------
            */ else if ($request->filled('from_date') && $request->filled('to_date')) {

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
            }

            /*
            |--------------------------------------------------------------------------
            | CASE 3: month/year
            |--------------------------------------------------------------------------
            */ else if ($request->filled('month') && $request->filled('year')) {

                try {
                    $parsedDate = Carbon::create((int) $request->year, (int) $request->month, 1);
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
            }

            /*
            |--------------------------------------------------------------------------
            | CASE 4: fallback date
            |--------------------------------------------------------------------------
            */ else {

                $statsDate = Carbon::today();

                if ($viewType === 'monthly') {
                    $startDate = $statsDate->copy()->startOfMonth();
                    $endDate = $statsDate->copy()->endOfMonth();
                } else {
                    $startDate = $statsDate->copy()->startOfDay();
                    $endDate = $statsDate->copy()->endOfDay();
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

            $employeeQuery->whereHas('attendanceProcesseds', function ($q) use ($viewType, $startDate, $endDate, $statsDate, $request) {

                // CASE 1: from/to date
                if ($request->filled('from_date') && $request->filled('to_date')) {

                    $q->whereBetween('date', [
                        $startDate->format('Y-m-d'),
                        $endDate->format('Y-m-d')
                    ]);
                }

                // CASE 2: month/year OR view_type monthly
                else if ($request->filled('month') && $request->filled('year') || $viewType === 'monthly') {

                    $q->whereMonth('date', $statsDate->month)
                        ->whereYear('date', $statsDate->year);
                }

                // CASE 3: daily
                else {
                    $q->whereBetween('date', [
                        $startDate->format('Y-m-d'),
                        $endDate->format('Y-m-d')
                    ]);
                }
            });
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

                // Paid holiday days per employee. Counted as distinct dates,
                // so a general and a site holiday on the same day - or plain
                // duplicate rows - are one day, and a holiday landing on a
                // weekly off or any other already-paid day adds nothing.
                $holidayDays = \App\Services\HolidayService::monthlyHolidayDays($employeeIds, $month, $year);

                $data = collect($employees->items())->map(function ($employee) use ($attendanceCounts, $leaveSummary, $holidayDays, $daysInMonth) {
                    $att = $attendanceCounts->get($employee->id);
                    $empLeave = $leaveSummary[$employee->id] ?? ['paid' => 0, 'unpaid' => 0];

                    $present = $att ? (int) $att->present_days : 0;
                    $absent = $att ? (int) $att->absent_days : 0;
                    $halfDay = $att ? (int) $att->half_days : 0;
                    $restDay = $att ? (int) $att->rest_days : 0;
                    $restDaysSetting = \App\Services\LeaveBalanceService::monthlyPaidRestDays();
                    $paidRestDays = min($restDay, $restDaysSetting);
                    //$leave = $att ? (int) $att->leave_days : 0;
                    $leave = $empLeave['paid'] + $empLeave['unpaid'];
                    $holidays = $holidayDays[$employee->id] ?? 0;

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
                        'rest_day' => "{$restDay}/{$restDaysSetting}",
                        'leave' => $leave,
                        'paid_leave' => $empLeave['paid'],
                        'unpaid_leave' => $empLeave['unpaid'],
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
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $isLimitNull = (!is_numeric($limit) || (int)$limit <= 0);
            $viewType = strtolower($request->input('view_type', 'daily'));

            // Parse Date and View Type (daily or monthly)
            $startDate = null;
            $endDate = null;
            $statsDate = null;

            if ($viewType === 'daily' && $request->filled('from_date')) {
                try {
                    $parsedDate = Carbon::parse($request->from_date)->startOfDay();
                    $statsDate = $parsedDate;
                    $startDate = $parsedDate->copy()->startOfDay();
                    if ($request->filled('to_date')) {
                        $endDate = Carbon::parse($request->to_date)->endOfDay();
                    } else {
                        $endDate = $parsedDate->copy()->endOfDay();
                    }
                } catch (\Exception $e) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Invalid from_date format.'
                    ], 422);
                }
            } else if ($request->filled('month') && $request->filled('year')) {
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
            $employeeQuery = \App\Models\Employee::query()->whereDate('joining_date', '<=', $endDate->format('Y-m-d'));
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
            $statsEmployeeIds = $employeeQuery->pluck('id')->toArray();
            $totalEmployeesCount = count($statsEmployeeIds);

            if ($viewType === 'daily') {
                $presentCount = 0;
                $absentCount = 0;
                $halfDayCount = 0;
                $leaveCount = 0;

                if ($totalEmployeesCount > 0) {
                    $employeesForStats = \App\Models\Employee::select('id', 'site_id', 'joining_date')
                        ->whereIn('id', $statsEmployeeIds)
                        ->get();

                    $processedRecords = AttendanceProcessed::whereIn('employee_id', $statsEmployeeIds)
                        ->whereDate('date', $statsDate->format('Y-m-d'))
                        ->get()
                        ->keyBy('employee_id');

                    $leaveEmployeeIds = \App\Models\Leave::whereIn('employee_id', $statsEmployeeIds)
                        ->where('status', 'approved')
                        ->whereDate('from_date', '<=', $statsDate->format('Y-m-d'))
                        ->whereDate('to_date', '>=', $statsDate->format('Y-m-d'))
                        ->pluck('employee_id')
                        ->toArray();
                    $leaveEmployeeIdsSet = array_flip($leaveEmployeeIds);

                    $isGeneralHoliday = \App\Models\Holiday::where('holiday_date', $statsDate->format('Y-m-d'))
                        ->where('is_active', true)
                        ->whereNull('site_id')
                        ->exists();

                    $holidaySiteIds = \App\Models\Holiday::where('holiday_date', $statsDate->format('Y-m-d'))
                        ->where('is_active', true)
                        ->whereNotNull('site_id')
                        ->pluck('site_id')
                        ->toArray();
                    $holidaySiteIdsSet = array_flip($holidaySiteIds);

                    foreach ($employeesForStats as $emp) {
                        if (\Carbon\Carbon::parse($statsDate->format('Y-m-d'))->lt(\Carbon\Carbon::parse($emp->joining_date))) {
                            continue;
                        }

                        $record = $processedRecords->get($emp->id);
                        if ($record) {
                            $status = $record->attendance_status;
                        } else {
                            $hasLeave = isset($leaveEmployeeIdsSet[$emp->id]);
                            $isHoliday = $isGeneralHoliday || isset($holidaySiteIdsSet[$emp->site_id]);

                            $status = null;
                            if ($hasLeave) {
                                $status = 'leave';
                            } elseif ($isHoliday) {
                                $status = 'holiday';
                            }
                        }

                        if ($attendanceStatusInput && $status !== $attendanceStatusInput) {
                            continue;
                        }

                        if ($status === 'present') {
                            $presentCount++;
                        } elseif ($status === 'absent') {
                            $absentCount++;
                        } elseif ($status === 'half_day') {
                            $halfDayCount++;
                        } elseif ($status === 'leave') {
                            $leaveCount++;
                        }
                    }
                }
            } else {
                $statsQuery = AttendanceProcessed::query();
                $statsQuery->whereMonth('date', $statsDate->month)
                    ->whereYear('date', $statsDate->year);

                $statsQuery->whereHas('employee', function ($q) use ($request, $statsDate, $viewType, $applyActiveFilter, $filterActive, $attendanceStatusInput, $endDate) {
                    $q->whereDate('joining_date', '<=', $endDate->format('Y-m-d'));
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
                            $aq->whereMonth('date', $statsDate->month)
                                ->whereYear('date', $statsDate->year);
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
            }

            if ($viewType === 'monthly') {
                $month = $statsDate->month;
                $year = $statsDate->year;
                $daysInMonth = Carbon::create($year, $month)->daysInMonth;

                // Build Employee query
                $employeeQuery = \App\Models\Employee::with(['site', 'department'])
                    ->whereDate('joining_date', '<=', $endDate->format('Y-m-d'));
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

                $employees = $employeeQuery->orderBy('name')->paginate($isLimitNull ? max(1, (clone $employeeQuery)->count()) : (int)$limit);
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

                    // Compensatory Rest leave (applied through Leave Management) gets
                    // its own attendance_processeds row on approval and is counted as
                    // rest_day from there — skip it here so it is not double counted
                    // as a generic leave day too.
                    if (optional($leave->leaveType)->register_group === 'compensatory_rest') {
                        continue;
                    }

                    $from = Carbon::parse($leave->from_date)->max($monthStart);
                    $to = Carbon::parse($leave->to_date)->min($monthEnd);
                    $days = $from->diffInDays($to) + 1;

                    if (!isset($leaveSummary[$empId])) {
                        $leaveSummary[$empId] = ['paid' => 0, 'unpaid' => 0];
                    }

                    $category = optional($leave->leaveType)->leave_category ?? 'unpaid';
                    if ($category === 'paid') {
                        $leaveSummary[$empId]['paid'] += $days;
                    } else {
                        $leaveSummary[$empId]['unpaid'] += $days;
                    }
                }

                // Paid holiday days per employee. Counted as distinct dates,
                // so a general and a site holiday on the same day - or plain
                // duplicate rows - are one day, and a holiday landing on a
                // weekly off or any other already-paid day adds nothing.
                $holidayDays = \App\Services\HolidayService::monthlyHolidayDays($employeeIds, $month, $year);

                $data = collect($employees->items())->map(function ($employee) use ($attendanceCounts, $leaveSummary, $holidayDays, $daysInMonth) {
                    $att = $attendanceCounts->get($employee->id);
                    $empLeave = $leaveSummary[$employee->id] ?? ['paid' => 0, 'unpaid' => 0];

                    $present = $att ? (int) $att->present_days : 0;
                    $absent = $att ? (int) $att->absent_days : 0;
                    $halfDay = $att ? (int) $att->half_days : 0;
                    // An approved Compensatory Rest leave gets its own
                    // attendance_processeds row (see LeaveController::syncAttendanceForLeave),
                    // so rest_day reads from attendance alone — no separate add-on here.
                    $restDay = $att ? (int) $att->rest_days : 0;
                    $restDaysSetting = \App\Services\LeaveBalanceService::monthlyPaidRestDays();

                    $paidRestDays = min($restDay, $restDaysSetting);
                    $leave = $empLeave['paid'] + $empLeave['unpaid'];

                    $holidays = $holidayDays[$employee->id] ?? 0;

                    // Payable days calculation matching payroll logic:
                    // Payable Days = Present + (Half Day * 0.5) + Rest Day + Paid Leave + Holiday
                    $payableDays = $present + ($halfDay * 0.5) + $paidRestDays + $empLeave['paid'] + $holidays;

                    return [
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->name,
                        'employee_code' => $employee->employee_code,
                        'site_id' => $employee->site_id,
                        'site_name' => $employee->site ? $employee->site->site_name : null,
                        'total_days' => $daysInMonth,
                        'present' => $present,
                        'absent' => $absent,
                        'half_day' => $halfDay,
                        'rest_day' => "{$restDay}/{$restDaysSetting}",
                        'leave' => $leave,
                        'paid_leave' => $empLeave['paid'],
                        'unpaid_leave' => $empLeave['unpaid'],
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

            // Build Employee Query to paginate all employees
            $employeeQuery = \App\Models\Employee::with([
                'site',
                'relay',
                'currentShiftAssignment.shift',
                'attendanceProcesseds' => function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('date', [
                        $startDate->format('Y-m-d'),
                        $endDate->format('Y-m-d')
                    ]);
                },
                'attendanceProcesseds.shift',
            ]);

            // Filter out employees registered after the start date
            $employeeQuery->whereDate('joining_date', '<=', $startDate->format('Y-m-d'));

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

            // Apply granular status filter
            if ($attendanceStatusInput) {
                if ($attendanceStatusInput === 'absent') {
                    // Check if the date is a general holiday
                    $isGeneralHoliday = \App\Models\Holiday::where('holiday_date', $startDate->format('Y-m-d'))
                        ->where('is_active', true)
                        ->whereNull('site_id')
                        ->exists();

                    if ($isGeneralHoliday) {
                        // On General Holiday, only show employees who are explicitly processed as 'absent'
                        $employeeQuery->whereHas('attendanceProcesseds', function ($sq) use ($startDate, $endDate) {
                            $sq->whereBetween('date', [
                                $startDate->format('Y-m-d'),
                                $endDate->format('Y-m-d')
                            ])->where('attendance_status', 'absent');
                        });
                    } else {
                        // On other days, show employees who are explicitly processed as 'absent',
                        // OR who have no record AND no approved leave AND no site-specific holiday.
                        $employeeQuery->where(function ($q) use ($startDate, $endDate) {
                            $q->whereHas('attendanceProcesseds', function ($sq) use ($startDate, $endDate) {
                                $sq->whereBetween('date', [
                                    $startDate->format('Y-m-d'),
                                    $endDate->format('Y-m-d')
                                ])->where('attendance_status', 'absent');
                            })->orWhere(function ($q2) use ($startDate, $endDate) {
                                $q2->whereDoesntHave('attendanceProcesseds', function ($sq) use ($startDate, $endDate) {
                                    $sq->whereBetween('date', [
                                        $startDate->format('Y-m-d'),
                                        $endDate->format('Y-m-d')
                                    ]);
                                })
                                    ->whereDoesntHave('leaves', function ($sq) use ($startDate, $endDate) {
                                        $sq->where('status', 'approved')
                                            ->whereDate('from_date', '<=', $startDate->format('Y-m-d'))
                                            ->whereDate('to_date', '>=', $startDate->format('Y-m-d'));
                                    })
                                    ->whereDoesntHave('site.holidays', function ($sq) use ($startDate) {
                                        $sq->where('holiday_date', $startDate->format('Y-m-d'))->where('is_active', true);
                                    });
                            });
                        });
                    }
                } elseif ($attendanceStatusInput === 'leave') {
                    // Show employees who are explicitly processed as 'leave',
                    // OR who have no record AND have an approved leave.
                    $employeeQuery->where(function ($q) use ($startDate, $endDate) {
                        $q->whereHas('attendanceProcesseds', function ($sq) use ($startDate, $endDate) {
                            $sq->whereBetween('date', [
                                $startDate->format('Y-m-d'),
                                $endDate->format('Y-m-d')
                            ])->where('attendance_status', 'leave');
                        })->orWhere(function ($q2) use ($startDate, $endDate) {
                            $q2->whereDoesntHave('attendanceProcesseds', function ($sq) use ($startDate, $endDate) {
                                $sq->whereBetween('date', [
                                    $startDate->format('Y-m-d'),
                                    $endDate->format('Y-m-d')
                                ]);
                            })
                                ->whereHas('leaves', function ($sq) use ($startDate, $endDate) {
                                    $sq->where('status', 'approved')
                                        ->whereDate('from_date', '<=', $startDate->format('Y-m-d'))
                                        ->whereDate('to_date', '>=', $startDate->format('Y-m-d'));
                                });
                        });
                    });
                } elseif ($attendanceStatusInput === 'rest_day') {
                    // Show employees who are explicitly processed as 'rest_day'
                    $employeeQuery->whereHas('attendanceProcesseds', function ($sq) use ($startDate, $endDate) {
                        $sq->whereBetween('date', [
                            $startDate->format('Y-m-d'),
                            $endDate->format('Y-m-d')
                        ])->where('attendance_status', 'rest_day');
                    });
                } else {
                    $employeeQuery->whereHas('attendanceProcesseds', function ($sq) use ($startDate, $endDate, $attendanceStatusInput) {
                        $sq->whereBetween('date', [
                            $startDate->format('Y-m-d'),
                            $endDate->format('Y-m-d')
                        ])->where('attendance_status', $attendanceStatusInput);
                    });
                }
            }

            $employees = $employeeQuery->orderBy('name')->paginate($isLimitNull ? max(1, (clone $employeeQuery)->count()) : (int)$limit);

            // A processed row only carries shift_id when the import supplied one.
            // Where it is null the shift still exists on the roster, so resolve it
            // the same way the register does: override -> relay mapping -> assignment.
            $pageEmployees = collect($employees->items());
            $rosterContext = $this->buildShiftRosterContext($pageEmployees, $startDate, $endDate);
            $shiftNames = \App\Models\Shift::pluck('shift_name', 'id');

            $dates = [];
            $currentDate = $startDate->copy();
            while ($currentDate->lte($endDate)) {
                $dates[] = $currentDate->format('Y-m-d');
                $currentDate->addDay();
            }

            $data = [];
            foreach ($employees->items() as $employee) {
                // Group processed records by date for this employee using a formatted date string key
                $empRecords = $employee->attendanceProcesseds->keyBy(function ($item) {
                    return \Carbon\Carbon::parse($item->date)->format('Y-m-d');
                });

                foreach ($dates as $dateStr) {
                    $carbonDate = \Carbon\Carbon::parse($dateStr);
                    // Check if date is before employee joining date, if so skip
                    if ($carbonDate->lt(\Carbon\Carbon::parse($employee->joining_date))) {
                        continue;
                    }

                    $item = $empRecords->get($dateStr);

                    if ($item) {
                        // Use existing processed record
                        $shiftId = $item->shift_id
                            ?: $this->resolveRosterShiftId($employee, $dateStr, $rosterContext);

                        $leaveType = in_array($item->attendance_status, \App\Services\AttendanceLeaveSync::LEAVE_STATUSES, true)
                            ? \App\Services\AttendanceLeaveSync::leaveTypeForDay($employee->id, $dateStr)
                            : ['leave_type_id' => null, 'leave_type_name' => null];

                        $data[] = [
                            'id' => $item->id,
                            'employee_id' => $employee->id,
                            'shift_id' => $shiftId,
                            'employee_name' => $employee->name,
                            'employee_code' => $employee->employee_code,
                            'site_id' => $employee->site_id,
                            'site_name' => $employee->site->site_name ?? null,
                            'relay_id' => $employee->relay_id,
                            'relay_name' => optional($employee->relay)->name,
                            'shift_name' => $shiftId ? ($shiftNames[$shiftId] ?? null) : null,
                            'date' => $carbonDate->format('d M Y'),
                            'check_in' => $item->check_in ? \Carbon\Carbon::parse($item->check_in)->format('H:i') :
                                null,
                            'check_out' => $item->check_out ? \Carbon\Carbon::parse($item->check_out)->format('H:i') :
                                null,
                            'working_hours' => $item->working_hours,
                            'late_minutes' => $item->late_minutes,
                            'early_exit_minutes' => $item->early_exit_minutes,
                            'attendance_status' => $item->attendance_status,
                            'attendance_status_label' => $item->attendance_status
                                ? ucwords(str_replace('_', ' ', $item->attendance_status))
                                : null,
                            'leave_type_id' => $leaveType['leave_type_id'],
                            'leave_type_name' => $leaveType['leave_type_name'],
                            'remarks' => $item->remarks,
                            'created_at' => $item->created_at,
                            'updated_at' => $item->updated_at,
                        ];
                    } else {
                        // Determine default status
                        $hasLeave = \App\Models\Leave::where('employee_id', $employee->id)
                            ->where('status', 'approved')
                            ->whereDate('from_date', '<=', $dateStr)->whereDate('to_date', '>=', $dateStr)
                            ->exists();

                        $isHoliday = \App\Models\Holiday::where('holiday_date', $dateStr)
                            ->where('is_active', true)
                            ->where(function ($q) use ($employee) {
                                $q->whereNull('site_id')
                                    ->orWhere('site_id', $employee->site_id);
                            })
                            ->exists();

                        $status = null;
                        if ($hasLeave) {
                            $status = 'leave';
                        } elseif ($isHoliday) {
                            $status = 'holiday';
                        }

                        // shift_id on the model resolves against today; this row is
                        // for $dateStr, so use the date-aware resolver instead.
                        $shiftId = $this->resolveRosterShiftId($employee, $dateStr, $rosterContext);

                        $leaveType = $status === 'leave'
                            ? \App\Services\AttendanceLeaveSync::leaveTypeForDay($employee->id, $dateStr)
                            : ['leave_type_id' => null, 'leave_type_name' => null];

                        $data[] = [
                            'id' => null,
                            'employee_id' => $employee->id,
                            'shift_id' => $shiftId,
                            'employee_name' => $employee->name,
                            'employee_code' => $employee->employee_code,
                            'site_id' => $employee->site_id,
                            'site_name' => $employee->site->site_name ?? null,
                            'relay_id' => $employee->relay_id,
                            'relay_name' => optional($employee->relay)->name,
                            'shift_name' => $shiftId ? ($shiftNames[$shiftId] ?? null) : null,
                            'date' => $carbonDate->format('d M Y'),
                            'check_in' => null,
                            'check_out' => null,
                            'working_hours' => 0.0,
                            'late_minutes' => 0,
                            'early_exit_minutes' => 0,
                            'attendance_status' => $status,
                            'attendance_status_label' => ucwords(str_replace('_', ' ', $status)),
                            'leave_type_id' => $leaveType['leave_type_id'],
                            'leave_type_name' => $leaveType['leave_type_name'],
                            'remarks' => null,
                            'created_at' => null,
                            'updated_at' => null,
                        ];
                    }
                }
            }

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
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }
}
