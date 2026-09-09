<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TrainingType;
use App\Http\Requests\StoreTrainingTypeRequest;
use App\Http\Requests\UpdateTrainingTypeRequest;
use App\Http\Resources\TrainingTypeResource;

class TrainingTypeController extends Controller
{
    /**
     * Active types only, unpaginated — the Schedule Training "Training Type"
     * dropdown.
     */
    public function publicIndex()
    {
        $types = TrainingType::where('is_active', 1)
            ->latest()
            ->get()
            ->map(function ($type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'status' => $type->is_active,
                ];
            });

        return response()->json([
            'status' => 200,
            'message' => 'Training type list fetched successfully',
            'data' => $types
        ]);
    }

    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $departments = TrainingType::query();

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
                'message' => 'TrainingType list fetched successfully',
                'data' => TrainingTypeResource::collection($departments),
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

    public function store(StoreTrainingTypeRequest $request)
    {
        $dept = TrainingType::create([
            'code' => $request->code,
            'name' => $request->name,
            'status' => 1
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'TrainingType created'
            // 'data' => new DepartmentResource($dept)
        ]);
    }

    public function show(int $id)
    {
        $dept = TrainingType::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'TrainingType not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new TrainingTypeResource($dept)
        ]);
    }
    public function update(UpdateTrainingTypeRequest $request, int $id)
    {
        $dept = TrainingType::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'TrainingType not found'
            ]);
        }

        $dept->update([
            'code' => $request->code,
            'name' => $request->name
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'TrainingType updated successfully'
        ]);
    }

    public function destroy(int $id)
    {
        $dept = TrainingType::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'TrainingType not found'
            ]);
        }

        $dept->delete();

        return response()->json([
            'status' => 200,
            'message' => 'TrainingType deleted successfully'
        ]);
    }
    public function toggleStatus(Request $request, int $id)
    {
        $dept = TrainingType::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'TrainingType not found'
            ]);
        }
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'TrainingType status updated successfully'
        ]);
    }
}
