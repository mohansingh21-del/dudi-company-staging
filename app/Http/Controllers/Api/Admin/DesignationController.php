<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Role;
use App\Http\Requests\StoreDesignationRequest;
use App\Http\Requests\UpdateDesignationRequest;
use App\Http\Resources\DesignationResource;
use Illuminate\Support\Str;

class DesignationController extends Controller
{
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $departments = Role::query();

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
                'message' => 'Designation list fetched successfully',
                'data' => DesignationResource::collection($departments),
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

    public function store(StoreDesignationRequest $request)
    {
        $dept = Role::create([
            'code' => $request->code,
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'status' => 1
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Designation created'
            // 'data' => new DepartmentResource($dept)
        ]);
    }
    public function update(UpdateDesignationRequest $request, int $id)
    {
        $dept = Role::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Designation not found'
            ]);
        }

        $dept->update([
            'code' => $request->code,
            'name' => $request->name,
            'slug' => Str::slug($request->name),
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Designation updated successfully'
        ]);
    }
    public function show(int $id)
    {
        $dept = Role::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Designation not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new DesignationResource($dept)
        ]);
    }


    public function toggleStatus(Request $request, int $id)
    {
        ///// dd("call");
        $dept = Role::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Designation not found'
            ]);
        }
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'Designation status updated successfully'
        ]);
    }

    public function getPublicDesignations()
    {
        try {
            $designations = Role::where('is_active', 1)->get();

            return response()->json([
                'status' => 200,
                'message' => 'Designations retrieved successfully',
                'data' => DesignationResource::collection($designations)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
