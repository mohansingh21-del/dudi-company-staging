<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Concerns\GuardsMasterDeactivation;
use Illuminate\Http\Request;
use App\Models\SubCategory;
use App\Http\Requests\StoreSubCategoryRequest;
use App\Http\Requests\UpdateSubCategoryRequest;
use App\Http\Resources\SubCategoryResource;

class SubCategoryController extends Controller
{
    use GuardsMasterDeactivation;

    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);
            $subCategories = SubCategory::with('category');

            // Filter by Category
            if ($request->filled('category_id')) {
                $subCategories->where('category_id', $request->category_id);
            }

            // Search by Name or Category Name
            if ($request->filled('search')) {
                $search = $request->search;
                $subCategories->where(function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%{$search}%")
                        ->orWhereHas('category', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        });
                });
            }

            if ($limit) {
                $paginated = $subCategories->paginate($limit, ['*'], 'page', $page);
                $response = [
                    'data' => SubCategoryResource::collection($paginated->items()),
                    'pagination' => [
                        'total' => $paginated->total(),
                        'current_page' => $paginated->currentPage(),
                        'per_page' => $paginated->perPage(),
                        'last_page' => $paginated->lastPage(),
                        'from' => $paginated->firstItem(),
                        'to' => $paginated->lastItem(),
                        'next_page_url' => $paginated->nextPageUrl(),
                        'previous_page_url' => $paginated->previousPageUrl(),
                    ]
                ];
            } else {
                $all = $subCategories->get();
                $response = [
                    'data' => SubCategoryResource::collection($all),
                    'pagination' => [
                        'total' => $all->count(),
                        'current_page' => 1,
                        'per_page' => $all->count(),
                        'last_page' => 1,
                        'from' => $all->isEmpty() ? 0 : 1,
                        'to' => $all->count(),
                    ]
                ];
            }

            return response()->json([
                'status' => 200,
                'message' => 'Subcategory list fetched successfully.',
                'data' => $response['data'],
                'pagination' => $response['pagination']
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function store(StoreSubCategoryRequest $request)
    {
        try {
            $subCategory = SubCategory::create([
                'category_id' => $request->category_id,
                'name' => $request->name,
                'is_active' => 1
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Subcategory created successfully',
                'data' => new SubCategoryResource($subCategory)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function show(int $id)
    {
        $subCategory = SubCategory::with('category')->find($id);

        if (!$subCategory) {
            return response()->json([
                'status' => 404,
                'message' => 'Subcategory not found'
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'data' => new SubCategoryResource($subCategory)
        ]);
    }

    public function update(UpdateSubCategoryRequest $request, int $id)
    {
        try {
            $subCategory = SubCategory::find($id);

            if (!$subCategory) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Subcategory not found'
                ], 404);
            }

            $subCategory->update([
                'category_id' => $request->category_id,
                'name' => $request->name
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Subcategory updated successfully',
                'data' => new SubCategoryResource($subCategory)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(int $id)
    {
        try {
            $subCategory = SubCategory::find($id);

            if (!$subCategory) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Subcategory not found'
                ], 404);
            }

            $subCategory->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Subcategory deleted successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(int $id)
    {
        try {
            $subCategory = SubCategory::find($id);

            if (!$subCategory) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Subcategory not found'
                ], 404);
            }

            if ($blocked = $this->blockDeactivation($subCategory, !$subCategory->is_active)) {
                return $blocked;
            }

            $subCategory->is_active = $subCategory->is_active ? 0 : 1;
            $subCategory->save();

            return response()->json([
                'status' => 200,
                'message' => 'Subcategory status updated successfully',
                'data' => new SubCategoryResource($subCategory)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Dropdown feed. category_id narrows it so a Category picker can cascade
     * into the Sub Category one next to it.
     */
    public function getPublicSubCategories(Request $request)
    {
        try {
            $query = SubCategory::with('category')->where('is_active', 1);

            if ($request->filled('category_id')) {
                $query->where('category_id', (int) $request->category_id);
            }

            $subCategories = $query->get();

            return response()->json([
                'status' => 200,
                'message' => 'Subcategories retrieved successfully',
                'data' => SubCategoryResource::collection($subCategories)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
