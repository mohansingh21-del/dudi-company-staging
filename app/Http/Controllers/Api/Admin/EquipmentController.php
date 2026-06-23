<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Equipment;
use App\Http\Requests\StoreEquipmentRequest;
use App\Http\Requests\UpdateEquipmentRequest;
use App\Http\Resources\EquipmentResource;

class EquipmentController extends Controller
{
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $departments = Equipment::query();

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
                'message' => 'Equipment list fetched successfully',
                'data' => EquipmentResource::collection($departments),
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

    public function store(StoreEquipmentRequest $request)
    {
        $dept = Equipment::create([
            'code' => $request->code,
            'name' => $request->name,
            'status' => 1
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Equipment created'
            // 'data' => new DepartmentResource($dept)
        ]);
    }

    public function show(int $id)
    {
        $dept = Equipment::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Equipment not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new EquipmentResource($dept)
        ]);
    }
    public function update(UpdateEquipmentRequest $request, int $id)
    {
        $dept = Equipment::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Equipment not found'
            ]);
        }

        $dept->update([
            'code' => $request->code,
            'name' => $request->name
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Equipment updated successfully'
        ]);
    }

    public function destroy(int $id)
    {
        $dept = Equipment::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Equipment not found'
            ]);
        }

        $dept->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Equipment deleted successfully'
        ]);
    }
    public function toggleStatus(Request $request, int $id)
    {
        $dept = Equipment::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Equipment not found'
            ]);
        }
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'Equipment status updated successfully'
        ]);
    }

    public function getPublicDepartments()
    {
        try {
            $departments = Equipment::where('is_active', 1)->get();

            return response()->json([
                'status' => 200,
                'message' => 'Equipment retrieved successfully',
                'data' => EquipmentResource::collection($departments)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function listCategories()
    {
        $categories = Equipment::where('is_active', 1)->get();

        $data = $categories->map(function ($category) {
            return [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'is_active' => $category->is_active,
            ];
        });

        return response()->json([
            'status' => 200,
            'message' => 'Machine categories retrieved successfully.',
            'data' => $data->values()->toArray(),
        ]);
    }
}
