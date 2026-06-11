<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
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

public function update(UpdateAttendanceRequest $request, $id)
    {
        try {

            $attendance = AttendanceProcessed::with('shift')->find($id);

            if (!$attendance) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Attendance not found'
                ]);
            }

            $data = $request->validated();

            $attendanceDate = Carbon::parse($attendance->date)->format('Y-m-d');

            $checkIn = Carbon::parse(
                $attendanceDate . ' ' . $data['check_in']
            );

            $checkOut = Carbon::parse(
                $attendanceDate . ' ' . $data['check_out']
            );

            if ($checkOut->lessThanOrEqualTo($checkIn)) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Check-out must be greater than check-in'
                ]);
            }

            $workingHours = round(
                $checkIn->diffInMinutes($checkOut) / 60,
                2
            );

            $lateMinutes = 0;
            $earlyExitMinutes = 0;

            if ($attendance->shift) {

                $shiftStart = Carbon::parse(
                    $attendanceDate . ' ' . $attendance->shift->start_time
                );

                $shiftEnd = Carbon::parse(
                    $attendanceDate . ' ' . $attendance->shift->end_time
                );

                if ($checkIn->gt($shiftStart)) {
                    $lateMinutes = $shiftStart->diffInMinutes($checkIn);
                }

                if ($checkOut->lt($shiftEnd)) {
                    $earlyExitMinutes = $checkOut->diffInMinutes($shiftEnd);
                }
            }

            $attendance->update([
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'working_hours' => $workingHours,
                'late_minutes' => $lateMinutes,
                'early_exit_minutes' => $earlyExitMinutes,
                'attendance_status' => $data['attendance_status'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Attendance updated successfully'
            ]);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
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

            $query = AttendanceProcessed::with(['employee', 'shift']);


            if ($request->filled('search')) {

                $search = $request->search;

                $query->where(function ($q) use ($search) {

                    $q->whereHas('employee', function ($e) use ($search) {

                        $e->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('employee_code', 'LIKE', "%{$search}%");
                    })

                        ->orWhereHas('shift', function ($s) use ($search) {

                            $s->where('shift_name', 'LIKE', "%{$search}%");
                        });
                });
            }


            if ($request->filled('from_date') && $request->filled('to_date')) {

                $query->whereBetween('date', [
                    $request->from_date,
                    $request->to_date
                ]);
            }


            if ($request->filled('attendance_status')) {

                $query->where('attendance_status', $request->attendance_status);
            }


            $attendance = $query->latest()->paginate($limit);


            $data = collect($attendance->items())->map(function ($item) {

                return [
                    'id' => $item->id,
                    'employee_id' => $item->employee_id,
                    'shift_id' => $item->shift_id,

                    'employee_name' => $item->employee->name ?? null,
                    'employee_code' => $item->employee->employee_code ?? null,
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
                    'remarks' => $item->remarks,

                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,


                ];
            });


            return response()->json([
                'status' => 200,
                'message' => 'Attendance fetched successfully',
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
