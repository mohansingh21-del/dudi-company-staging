<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\User;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    use ApiResponse;

    /**
     * Mark Attendance (Check-in)
     */
    public function markAttendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userid' => 'required|numeric|exists:users,id',
            'lat' => 'required',
            'long' => 'required',
            // 'location_name' => 'nullable|string',
            // 'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        if ($request->lat == '0.0' || $request->long == '0.0') {
            return $this->errorResponse('Latitude and Longitude are not valid', 400);
        }

        $date = Carbon::now()->format('Y-m-d');
        $time = Carbon::now()->format('H:i:s');

        $existingAttendance = Attendance::where('user_id', $request->userid)
            ->where('date', $date)
            ->first();

        if ($existingAttendance && $existingAttendance->login_time != null) {
            return $this->errorResponse('You have already marked check-in for today.', 400);
        }

        $attendance = $existingAttendance ?: new Attendance();
        $attendance->user_id = $request->userid;
        $attendance->date = $date;
        $attendance->login_time = $time;
        $attendance->login_latitude = $request->lat;
        $attendance->login_longitude = $request->long;
        // $attendance->login_location_name = $request->location_name;
        // $attendance->description = $request->description;
        $attendance->is_approved = '1'; // Auto-approved as Present

        $attendance->save();

        return $this->successResponse([
            'loginstatus' => '1',
            'attendance' => $attendance
        ], 'Attendance check-in successful');
    }

    /**
     * Mark Attendance Out (Check-out)
     */
    public function markOutAttendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userid' => 'required|numeric|exists:users,id',
            'lat' => 'required',
            'long' => 'required',
            // 'location_name' => 'nullable|string',
            // 'comments' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        if ($request->lat == '0.0' || $request->long == '0.0') {
            return $this->errorResponse('Latitude and Longitude are not valid', 400);
        }

        $date = Carbon::now()->format('Y-m-d');
        $time = Carbon::now()->format('H:i:s');

        $attendance = Attendance::where('user_id', $request->userid)
            ->where('date', $date)
            ->first();

        if (!$attendance || $attendance->login_time == null) {
            return $this->errorResponse('You must check-in first before checking out.', 400);
        }

        if ($attendance->logout_time != null) {
            return $this->errorResponse('You have already marked check-out for today.', 400);
        }

        $attendance->logout_time = $time;
        $attendance->logout_latitude = $request->lat;
        $attendance->logout_longitude = $request->long;
        // $attendance->logout_location_name = $request->location_name;
        // $attendance->comments = $request->comments;
        $attendance->save();

        return $this->successResponse([
            'loginstatus' => '2',
            'attendance' => $attendance
        ], 'Attendance check-out successful');
    }

    /**
     * Get attendance history
     */
    public function getAttendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userid' => 'required|exists:users,id',
            'date' => 'nullable|date_format:Y-m',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $userid = $request->userid;
        if ($request->date) {
            $start_date = Carbon::parse($request->date)->startOfMonth()->toDateString();
            $end_date = Carbon::parse($request->date)->endOfMonth()->toDateString();
            $monthName = Carbon::parse($request->date)->format('F');
        } else {
            $start_date = Carbon::now()->startOfMonth()->toDateString();
            $end_date = Carbon::now()->endOfMonth()->toDateString();
            $monthName = Carbon::now()->format('F');
        }

        $attendances = Attendance::where('user_id', $userid)
            ->whereBetween('date', [$start_date, $end_date])
            ->orderBy('date', 'desc')
            ->get();

        $presentDays = 0;
        $history = [];

        foreach ($attendances as $item) {
            $status = 'pending';
            if ($item->is_approved == '1') {
                $status = 'present';
                $presentDays += 1;
            } elseif ($item->is_approved == '2') {
                $status = 'absent';
            } elseif ($item->is_approved == '3') {
                $status = 'halfday';
                $presentDays += 0.5;
            } elseif ($item->is_approved == '4') {
                $status = 'leave';
            }

            $history[] = [
                'date' => $item->date,
                'login_time' => $item->login_time,
                'logout_time' => $item->logout_time,
                'status' => $status,
                'is_approved' => $item->is_approved,
                'login_location' => $item->login_location_name,
                'logout_location' => $item->logout_location_name,
            ];
        }

        return $this->successResponse([
            'current_date' => now()->format('Y-m-d'),
            'current_time' => now()->format('H:i:s'),
            'total_present_days' => (string) $presentDays,
            'current_month' => $monthName,
            'history' => $history
        ], 'Attendance history fetched successfully');
    }

    /**
     * Check current attendance status
     */
    public function checkStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userid' => 'required|numeric|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        $date = Carbon::now()->format('Y-m-d');
        $attendance = Attendance::where('user_id', $request->userid)
            ->where('date', $date)
            ->first();

        if (!$attendance) {
            return $this->successResponse(['loginstatus' => '0'], 'Not marked yet for today');
        }

        if ($attendance->login_time != null && $attendance->logout_time != null) {
            return $this->successResponse(['loginstatus' => '2'], 'Already checked out for today');
        }

        if ($attendance->login_time != null) {
            return $this->successResponse(['loginstatus' => '1'], 'Checked in but not checked out');
        }

        return $this->successResponse(['loginstatus' => '0'], 'Not marked yet for today');
    }
    /**
     * Submit Correction Request
     */
    public function requestCorrection(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userid' => 'required|numeric|exists:users,id',
            'attendance_id' => 'required|numeric|exists:attendances,id',
            'date' => 'required|date',
            'login_time' => 'nullable',
            'logout_time' => 'nullable',
            'is_approved' => 'required|in:1,2,3,4',
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        // Check if there is already a pending correction for this date
        $existingCorrection = \App\Models\AttendanceCorrection::where('user_id', $request->userid)
            ->where('date', $request->date)
            ->where('status', '0')
            ->first();

        if ($existingCorrection) {
            return $this->errorResponse('You already have a pending correction request for this date.', 400);
        }

        $correction = \App\Models\AttendanceCorrection::create([
            'user_id' => $request->userid,
            'attendance_id' => $request->attendance_id,
            'date' => $request->date,
            'login_time' => $request->login_time,
            'logout_time' => $request->logout_time,
            'is_approved' => $request->is_approved,
            'remarks' => $request->remarks,
            'status' => '0', // Pending
        ]);

        return $this->successResponse($correction, 'Correction request submitted successfully');
    }
}
