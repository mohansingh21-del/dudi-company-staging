<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Concerns\GuardsMasterDeactivation;
use Illuminate\Http\Request;
use App\Models\Category;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;

class CategoryController extends Controller
{
    use GuardsMasterDeactivation;

    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);
            $categories = Category::query();

            // Search
            if ($request->filled('search')) {
                $search = $request->search;
                $categories->where(function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%{$search}%");
                });
            }

            if ($limit) {
                $paginated = $categories->paginate($limit, ['*'], 'page', $page);
                $response = [
                    'data' => CategoryResource::collection($paginated->items()),
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
                $all = $categories->get();
                $response = [
                    'data' => CategoryResource::collection($all),
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
                'message' => 'Categories retrieved successfully.',
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

    public function store(StoreCategoryRequest $request)
    {
        try {
            $category = Category::create([
                'name' => $request->name,
                'is_active' => 1
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Category created successfully',
                'data' => new CategoryResource($category)
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
        $category = Category::find($id);

        if (!$category) {
            return response()->json([
                'status' => 404,
                'message' => 'Category not found'
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'data' => new CategoryResource($category)
        ]);
    }

    public function update(UpdateCategoryRequest $request, int $id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Category not found'
                ], 404);
            }

            $category->update([
                'name' => $request->name
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Category updated successfully',
                'data' => new CategoryResource($category)
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
            $category = Category::find($id);

            if (!$category) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Category not found'
                ], 404);
            }

            $category->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Category deleted successfully'
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
            $category = Category::find($id);

            if (!$category) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Category not found'
                ], 404);
            }

            if ($blocked = $this->blockDeactivation($category, !$category->is_active)) {
                return $blocked;
            }

            $category->is_active = $category->is_active ? 0 : 1;
            $category->save();

            return response()->json([
                'status' => 200,
                'message' => 'Category status updated successfully',
                'data' => new CategoryResource($category)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function getPublicCategories(Request $request)
    {
        try {
            $categories = Category::where('is_active', 1);

            if ($request->filled('search')) {
                $categories->where('name', 'LIKE', "%{$request->search}%");
            }

            return response()->json([
                'status' => 200,
                'message' => 'Categories retrieved successfully',
                'data' => CategoryResource::collection($categories->get())
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
