<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\ServiceSparePart;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);
            $products = Product::with('subCategory.category');

            // Filter by SubCategory
            if ($request->filled('sub_category_id')) {
                $products->where('sub_category_id', $request->sub_category_id);
            }

            // Search by Name, Subcategory Name or Category Name
            if ($request->filled('search')) {
                $search = $request->search;
                $products->where(function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%{$search}%")
                        ->orWhereHas('subCategory', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%")
                              ->orWhereHas('category', function ($q2) use ($search) {
                                  $q2->where('name', 'LIKE', "%{$search}%");
                              });
                        });
                });
            }

            if ($limit) {
                $paginated = $products->paginate($limit, ['*'], 'page', $page);
                $response = [
                    'data' => ProductResource::collection($paginated->items()),
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
                $all = $products->get();
                $response = [
                    'data' => ProductResource::collection($all),
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
                'message' => 'Product list fetched successfully.',
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

    public function store(StoreProductRequest $request)
    {
        try {
            $product = Product::create([
                'sub_category_id' => $request->sub_category_id,
                'name' => $request->name,
                'min_stock' => $request->min_stock,
                'is_active' => 1
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Product created successfully',
                'data' => new ProductResource($product)
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
        $product = Product::with('subCategory.category')->find($id);

        if (!$product) {
            return response()->json([
                'status' => 404,
                'message' => 'Product not found'
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'data' => new ProductResource($product)
        ]);
    }

    public function update(UpdateProductRequest $request, int $id)
    {
        try {
            $product = Product::find($id);

            if (!$product) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Product not found'
                ], 404);
            }

            $oldMinStock = (int) $product->min_stock;

            $product->update([
                'sub_category_id' => $request->sub_category_id,
                'name' => $request->name,
                'min_stock' => $request->min_stock
            ]);

            // Moving the line can put stocked rows under it, or lift them over,
            // without a unit moving — the alerts are re-checked either way.
            if ((int) $product->min_stock !== $oldMinStock) {
                app(\App\Services\InventoryAlertService::class)
                    ->minStockChanged($product, $oldMinStock, (int) $product->min_stock, auth()->id());
            }

            return response()->json([
                'status' => 200,
                'message' => 'Product updated successfully',
                'data' => new ProductResource($product)
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
            $product = Product::find($id);

            if (!$product) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Product not found'
                ], 404);
            }

            $product->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Product deleted successfully'
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
            $product = Product::find($id);

            if (!$product) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Product not found'
                ], 404);
            }

            // A product can only be switched off once nothing depends on it:
            // no stock left at any store, never issued to an employee and never
            // used as a spare part on a service record.
            if ($product->is_active) {
                $blockedBy = null;

                if ($product->inventories()->where('left_quantity', '>', 0)->exists()) {
                    $blockedBy = 'it still has stock in inventory';
                } elseif ($product->assignments()->exists()) {
                    $blockedBy = 'it is assigned to an employee';
                } elseif (ServiceSparePart::whereHas('inventory', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })->exists()) {
                    $blockedBy = 'it is used in a service record';
                }

                if ($blockedBy) {
                    return response()->json([
                        'status' => 422,
                        'message' => "This product cannot be deactivated because {$blockedBy}."
                    ], 422);
                }
            }

            $product->is_active = $product->is_active ? 0 : 1;
            $product->save();

            return response()->json([
                'status' => 200,
                'message' => 'Product status updated successfully',
                'data' => new ProductResource($product)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Dropdown feed. The two filters let a Category / Sub Category pair of
     * pickers cascade into the Product one beside them.
     */
    public function getPublicProducts(Request $request)
    {
        try {
            $query = Product::with('subCategory.category')->where('is_active', 1);

            if ($request->filled('sub_category_id')) {
                $query->where('sub_category_id', (int) $request->sub_category_id);
            }

            if ($request->filled('category_id')) {
                $categoryId = (int) $request->category_id;
                $query->whereHas('subCategory', function ($q) use ($categoryId) {
                    $q->where('category_id', $categoryId);
                });
            }

            $products = $query->get();

            return response()->json([
                'status' => 200,
                'message' => 'Products retrieved successfully',
                'data' => ProductResource::collection($products)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
