<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Leave;
use App\Http\Requests\StoreLeaveRequest;
use App\Http\Requests\UpdateLeaveRequest;
use App\Http\Resources\LeaveResource;
use App\Http\Requests\ApproveLeaveRequest;
use Illuminate\Support\Facades\Auth;
use App\Imports\LeaveImport;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
class LeaveController extends Controller
{
    public function index(Request $request)
    {
        try {

    $limit = $request->input('limit', 10);

    $leaves = Leave::with(['employee', 'leaveType', 'approver']);
    
    if ($request->filled('search')) {

        $search = $request->search;

        $leaves->where(function ($q) use ($search) {

            // Status & Reason
            $q->where('status', 'LIKE', "%{$search}%")
                ->orWhere('reason', 'LIKE', "%{$search}%");

            // Employee Name
            $q->orWhereHas('employee', function ($employee) use ($search) {
                $employee->where('name', 'LIKE', "%{$search}%");
            });

            // Leave Type Name
            $q->orWhereHas('leaveType', function ($leaveType) use ($search) {
                $leaveType->where('name', 'LIKE', "%{$search}%");
            });
        });
    }
    // Employee Filter
        if ($request->filled('employee_id')) {
            $leaves->where('employee_id', $request->employee_id);
        }

        // Status Filter
        if ($request->filled('status')) {
            $leaves->where('status', $request->status);
        }

        // Month Filter
        if ($request->filled('month_year')) {

    [$year, $month] = explode('-', $request->month_year);

    $leaves->whereYear('from_date', $year)
           ->whereMonth('to_date', $month);
      }

    $leaves = $leaves->latest()->paginate($limit);

    return response()->json([
        'status' => 200,
        'message' => 'Leaves fetched successfully',
        'data' => LeaveResource::collection($leaves),
        'pagination' => [
            'current_page' => $leaves->currentPage(),
            'last_page' => $leaves->lastPage(),
            'per_page' => $leaves->perPage(),
            'total' => $leaves->total(),
            'from' => $leaves->firstItem(),
            'to' => $leaves->lastItem(),
        ]
    ]);

} catch (\Throwable $th) {

    return response()->json([
        'status' => 500,
        'message' => $th->getMessage()
    ]);
}
    }
   public function bulkUpload(Request $request)
{
    $request->validate([
        'file' => 'required|file|mimes:xlsx,xls,csv'
    ]);

    try {

        $import = new LeaveImport();

        Excel::import($import, $request->file('file'));

        if (count($import->getErrors()) > 0) {

            return response()->json([
                'status' => 422,
                'message' => 'Some rows failed validation',
                'errors' => $import->getErrors()
            ], 422);
        }

        return response()->json([
            'status' => 200,
            'message' => 'Leaves uploaded successfully'
        ], 200);

    } catch (\Exception $e) {

        return response()->json([
            'status' => 500,
            'message' => $e->getMessage()
        ], 500);
    }
}
    public function store(StoreLeaveRequest $request)
    {
        $leave = Leave::create([
            'employee_id' => $request->employee_id,
            'leave_type_id' => $request->leave_type_id,
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
            'reason' => $request->reason,
            'status' => $request->status ?? 'pending',
            'approved_by' => $request->approved_by,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Leave created successfully',
            'data' => new LeaveResource($leave)
        ]);
    }
    public function approveReject_old(ApproveLeaveRequest $request, int $id)
    {
        $leave = Leave::find($id);

        if (!$leave) {
            return response()->json([
                'status' => 404,
                'message' => 'Leave not found'
            ]);
        }

        // Optional: prevent re-approval changes
        if ($leave->status !== 'pending') {
            return response()->json([
                'status' => 400,
                'message' => 'Leave already processed'
            ]);
        }

        $leave->update([
            'status' => $request->status,
            'approved_by' => $request->approved_by,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Leave ' . $request->status . ' successfully',
            'data' => new \App\Http\Resources\LeaveResource($leave)
        ]);
    }
    public function approveReject(ApproveLeaveRequest $request, int $id)
    {
        $leave = Leave::find($id);

        if (!$leave) {
            return response()->json([
                'status' => 404,
                'message' => 'Leave not found'
            ]);
        }

        if ($leave->status !== 'pending') {
            return response()->json([
                'status' => 400,
                'message' => 'Leave already processed'
            ]);
        }

        // Backstop for the monthly Compensatory Rest cap. Applying already
        // enforces it, but leaves that predate the rule or came in through the
        // bulk sheet can still be sitting pending, and approving them should not
        // put the month over. Excludes this leave so its own days are counted
        // once, as the ones being added.
        if ($request->status === 'approved'
            && optional($leave->leaveType)->register_group === 'compensatory_rest') {

            $capMessage = \App\Services\LeaveBalanceService::compRestLeaveCapMessage(
                $leave->employee_id,
                Carbon::parse($leave->from_date)->format('Y-m-d'),
                Carbon::parse($leave->to_date)->format('Y-m-d'),
                $leave->id
            );

            if ($capMessage) {
                return response()->json([
                    'status' => 422,
                    'message' => $capMessage
                ], 422);
            }
        }

        $leave->update([
            'status' => $request->status,
            'approved_by' => Auth::id(),
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Leave ' . $request->status . ' successfully',
            'data' => new LeaveResource($leave)
        ]);
    }
    public function show(int $id)
    {
       $leave = Leave::with(['employee', 'leaveType', 'approver'])->find($id);

        if (!$leave) {
            return response()->json([
                'status' => 404,
                'message' => 'Leave not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new LeaveResource($leave)
        ]);
    }

    public function update(UpdateLeaveRequest $request, int $id)
    {
        $leave = Leave::find($id);

        if (!$leave) {
            return response()->json([
                'status' => 404,
                'message' => 'Leave not found'
            ]);
        }

        $leave->update([
            'employee_id' => $request->employee_id,
            'leave_type_id' => $request->leave_type_id,
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
            'reason' => $request->reason,
            'status' => $request->status,
            'approved_by' => $request->approved_by,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Leave updated successfully'
        ]);
    }

    public function destroy(int $id)
    {
        $leave = Leave::find($id);

        if (!$leave) {
            return response()->json([
                'status' => 404,
                'message' => 'Leave not found'
            ]);
        }

        $leave->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Leave deleted successfully'
        ]);
    }
}
