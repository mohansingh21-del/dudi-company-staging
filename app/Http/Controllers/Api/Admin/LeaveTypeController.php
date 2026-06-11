<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LeaveType;
use App\Http\Requests\StoreLeaveTypeRequest;
use App\Http\Requests\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;

class LeaveTypeController extends Controller
{
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $departments = LeaveType::query();

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $departments->where(function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('leave_category', 'LIKE', "%{$search}%");
                });
            }

            $departments = $departments
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'LeaveType list fetched successfully',
                'data' => LeaveTypeResource::collection($departments),
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

    public function store(StoreLeaveTypeRequest $request)
    {
        $dept = LeaveType::create([
            'name' => $request->name,
            'leave_category' => $request->leave_category,
            'allowed_days' => $request->Annual_limit,
            'status' => 1
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'LeaveType created'
            // 'data' => new DepartmentResource($dept)
        ]);
    }

    public function show(int $id)
    {
        $dept = LeaveType::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'LeaveType not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new LeaveTypeResource($dept)
        ]);
    }
    public function update(UpdateLeaveTypeRequest $request, int $id)
    {
        $dept = LeaveType::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'LeaveType not found'
            ]);
        }

        $dept->update([
            'name' => $request->name,
            'leave_category' => $request->leave_category,
            'allowed_days' => $request->Annual_limit,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Leave Type updated successfully'
        ]);
    }

    public function destroy(int $id)
    {
        $dept = LeaveType::find($id);

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
        $dept = LeaveType::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'LeaveType not found'
            ]);
        }
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'LeaveType status updated successfully'
        ]);
    }
}
