<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Concerns\GuardsMasterDeactivation;
use App\Models\DelayCategory;
use App\Http\Requests\StoreDelayCategoryRequest;
use App\Http\Requests\UpdateDelayCategoryRequest;
use App\Http\Resources\DelayCategoryResource;
use Illuminate\Http\Request;

class DelayCategoryController extends Controller
{
    use GuardsMasterDeactivation;

    public function publicIndex()
    {
        $categories = DelayCategory::where('is_active', 1)
            ->latest()
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'delay_category' => $category->delay_category,
                    'description' => $category->description,
                    'status' => $category->is_active,
                ];
            });

        return response()->json([
            'status' => 200,
            'message' => 'Delay category list fetched successfully',
            'data' => $categories
        ]);
    }

    public function index(Request $request)
    {
        $limit = $request->input('limit', 10);

        $categories = DelayCategory::query();

        // Search Delay Category + Description
        if ($request->filled('search')) {
            $search = $request->search;
            $categories->where(function ($query) use ($search) {
                $query->where('delay_category', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $categories = $categories
            ->latest()
            ->paginate($limit);

        return response()->json([
            'status' => 200,
            'message' => 'Delay category list fetched successfully',
            'data' => DelayCategoryResource::collection($categories),
            'pagination' => [
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
                'from' => $categories->firstItem(),
                'to' => $categories->lastItem()
            ]
        ]);
    }

    public function store(StoreDelayCategoryRequest $request)
    {
        $category = DelayCategory::create([
            'delay_category' => $request->delay_category,
            'description' => $request->description
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Delay category created successfully'
        ], 200);
    }

    public function show(int $id)
    {
        $category = DelayCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 404,
                'message' => 'Delay category not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new DelayCategoryResource($category)
        ]);
    }

    public function update(UpdateDelayCategoryRequest $request, $id)
    {
        $category = DelayCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 404,
                'message' => 'Delay category not found'
            ]);
        }

        $category->update([
            'delay_category' => $request->delay_category,
            'description' => $request->description ?? $category->description,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Delay category updated successfully'
        ]);
    }

    public function destroy($id)
    {
        $category = DelayCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 404,
                'message' => 'Delay category not found'
            ]);
        }

        $category->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Delay category deleted successfully'
        ]);
    }

    public function toggleStatus(Request $request, int $id)
    {
        $category = DelayCategory::find($id);

        if (!$category) {
            return response()->json([
                'status' => 404,
                'message' => 'Delay category not found'
            ]);
        }

        $request->validate([
            'status' => 'required|in:0,1'
        ]);


        if ($blocked = $this->blockDeactivation($category, $request->status)) {
            return $blocked;
        }

        $category->is_active = $request->status ? 1 : 0;
        $category->save();

        return response()->json([
            'status' => 200,
            'message' => 'Delay category status updated successfully'
        ]);
    }
}
