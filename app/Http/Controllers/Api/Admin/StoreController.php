<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Concerns\GuardsMasterDeactivation;
use App\Http\Requests\StoreStoreRequest;
use App\Http\Requests\UpdateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Models\Inventory;
use Illuminate\Http\Request;

/**
 * Stores master: the outside stores this mine draws spare parts from.
 *
 * The stock each store holds lives in InventoryController; this is the
 * name/description/active-flag master only.
 */
class StoreController extends Controller
{
    use GuardsMasterDeactivation;

    /**
     * Active stores for dropdowns.
     */
    public function publicIndex()
    {
        try {
            $stores = Store::where('is_active', 1)
                ->orderBy('name')
                ->get()
                ->map(function ($store) {
                    return [
                        'id'          => $store->id,
                        'name'        => $store->name,
                        'description' => $store->description,
                        'status'      => $store->is_active,
                    ];
                });

            return response()->json([
                'status'  => 200,
                'message' => 'Store list fetched successfully',
                'data'    => $stores
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);

            $stores = Store::withCount('inventories as products_count');

            if ($request->filled('search')) {
                $search = $request->search;
                $stores->where(function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%");
                });
            }

            if ($request->filled('status')) {
                $stores->where('is_active', (int) $request->status);
            }

            $stores = $stores->latest()->paginate($limit);

            return response()->json([
                'status'  => 200,
                'message' => 'Store list fetched successfully',
                'data'    => StoreResource::collection($stores),
                'pagination' => [
                    'current_page' => $stores->currentPage(),
                    'last_page'    => $stores->lastPage(),
                    'per_page'     => $stores->perPage(),
                    'total'        => $stores->total(),
                    'from'         => $stores->firstItem(),
                    'to'           => $stores->lastItem()
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function store(StoreStoreRequest $request)
    {
        try {
            Store::create([
                'name'        => $request->name,
                'description' => $request->description,
                'is_active'   => $request->filled('is_active') ? (int) $request->is_active : 1,
            ]);

            return response()->json([
                'status'  => 200,
                'message' => 'Store created successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function show(int $id)
    {
        try {
            $store = Store::withCount('inventories as products_count')->find($id);

            if (!$store) {
                return response()->json([
                    'status'  => 404,
                    'message' => 'Store not found'
                ], 404);
            }

            return response()->json([
                'status'  => 200,
                'message' => 'Store fetched successfully',
                'data'    => new StoreResource($store)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateStoreRequest $request, int $id)
    {
        try {
            $store = Store::find($id);

            if (!$store) {
                return response()->json([
                    'status'  => 404,
                    'message' => 'Store not found'
                ], 404);
            }

            $store->name = $request->name;
            $store->description = $request->description;

            if ($request->filled('is_active')) {
                $store->is_active = (int) $request->is_active;
            }

            $store->save();

            return response()->json([
                'status'  => 200,
                'message' => 'Store updated successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Hard delete, and its inventory rows cascade with it. Refuse while the store
     * still holds mapped products: deleting would take that stock and its
     * history with it silently. Unmap first, or deactivate instead.
     */
    public function destroy(int $id)
    {
        try {
            $store = Store::find($id);

            if (!$store) {
                return response()->json([
                    'status'  => 404,
                    'message' => 'Store not found'
                ], 404);
            }

            $mappedCount = Inventory::where('store_id', $id)->count();

            if ($mappedCount > 0) {
                return response()->json([
                    'status'  => 422,
                    'message' => "Cannot delete this store because {$mappedCount} product(s) are still mapped to it. Remove the mapped products first, or deactivate the store instead."
                ], 422);
            }

            $store->delete();

            return response()->json([
                'status'  => 200,
                'message' => 'Store deleted successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(Request $request, int $id)
    {
        $store = Store::find($id);

        if (!$store) {
            return response()->json([
                'status'  => 404,
                'message' => 'Store not found'
            ], 404);
        }

        // Outside the try/catch below on purpose: a ValidationException is a
        // Throwable, and catching it here would turn a 422 into a 500.
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        if ($blocked = $this->blockDeactivation($store, $request->status)) {
            return $blocked;
        }

        try {
            $store->is_active = $request->status ? 1 : 0;
            $store->save();

            return response()->json([
                'status'  => 200,
                'message' => 'Store status updated successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
