<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Department;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;

class DepartmentsController extends Controller
{
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $departments = Department::query();

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $departments->where(function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%{$search}%");
                });
            }

            $departments = $departments
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Department list fetched successfully',
                'data' => DepartmentResource::collection($departments),
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

    public function store(StoreDepartmentRequest $request)
    {
        $dept = Department::create([
            'code' => $request->code,
            'name' => $request->name,
            'status' => 1
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Department created'
            // 'data' => new DepartmentResource($dept)
        ]);
    }

    public function show(int $id)
    {
        $dept = Department::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Department not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new DepartmentResource($dept)
        ]);
    }
    public function update(UpdateDepartmentRequest $request, int $id)
    {
        $dept = Department::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Department not found'
            ]);
        }

        $dept->update([
            'code' => $request->code,
            'name' => $request->name
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Department updated successfully'
        ]);
    }

    public function destroy(int $id)
    {
        $dept = Department::find($id);

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
        $dept = Department::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Department not found'
            ]);
        }
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'Department status updated successfully'
        ]);
    }

    public function getPublicDepartments()
    {
        try {
            $departments = Department::where('is_active', 1)->get();

            return response()->json([
                'status' => 200,
                'message' => 'Departments retrieved successfully',
                'data' => DepartmentResource::collection($departments)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
