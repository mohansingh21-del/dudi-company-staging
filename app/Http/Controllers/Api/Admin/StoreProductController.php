<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStoreProductRequest;
use App\Http\Requests\UpdateStoreProductRequest;
use App\Http\Resources\InventoryLogResource;
use App\Http\Resources\StoreProductResource;
use App\Models\InventoryLog;
use App\Models\StoreProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The second inventory: what each outside store holds.
 *
 * Mirrors InventoryController for the mine's own stock, with two differences:
 * every row is scoped to a store, and the floor a deduction may not cross is
 * this row's own `threshold` rather than `products.min_stock`. The product
 * catalog itself is shared — these rows point at the same `products` table.
 */
class StoreProductController extends Controller
{
    /**
     * Standard eager loads for the shared product catalog.
     *
     * @var array
     */
    protected $with = ['store', 'product.subCategory.category'];

    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);

            $storeProducts = StoreProduct::with($this->with);

            if ($request->filled('store_id')) {
                $storeProducts->where('store_id', (int) $request->store_id);
            }

            if ($request->filled('product_id')) {
                $storeProducts->where('product_id', (int) $request->product_id);
            }

            // Stock sitting at or under its store's floor.
            if ($request->boolean('low_stock')) {
                $storeProducts->whereColumn('left_quantity', '<=', 'threshold');
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $storeProducts->where(function ($query) use ($search) {
                    $query->whereHas('product', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%")
                            ->orWhereHas('subCategory', function ($sq) use ($search) {
                                $sq->where('name', 'LIKE', "%{$search}%")
                                    ->orWhereHas('category', function ($cq) use ($search) {
                                        $cq->where('name', 'LIKE', "%{$search}%");
                                    });
                            });
                    })->orWhereHas('store', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    });
                });
            }

            $storeProducts = $storeProducts->latest()->paginate($limit);

            return response()->json([
                'status'  => 200,
                'message' => 'Store inventory list fetched successfully',
                'data'    => StoreProductResource::collection($storeProducts),
                'pagination' => [
                    'current_page' => $storeProducts->currentPage(),
                    'last_page'    => $storeProducts->lastPage(),
                    'per_page'     => $storeProducts->perPage(),
                    'total'        => $storeProducts->total(),
                    'from'         => $storeProducts->firstItem(),
                    'to'           => $storeProducts->lastItem()
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Map a product to a store with an opening quantity and threshold.
     *
     * Re-posting a pair that already exists replenishes it instead of failing,
     * matching InventoryController@store — the unique(store_id, product_id)
     * index is a safety net, not the intended error path.
     */
    public function store(StoreStoreProductRequest $request)
    {
        try {
            $quantity = (float) $request->quantity;
            $threshold = $request->filled('threshold') ? (float) $request->threshold : 0.00;

            $storeProduct = StoreProduct::where('store_id', $request->store_id)
                ->where('product_id', $request->product_id)
                ->first();

            DB::beginTransaction();

            if ($storeProduct) {
                $storeProduct->quantity += $quantity;
                $storeProduct->left_quantity += $quantity;

                if ($request->filled('threshold')) {
                    $storeProduct->threshold = $threshold;
                }

                $storeProduct->save();
                $action = 'replenished';
                $message = 'Store stock replenished successfully';
            } else {
                $storeProduct = StoreProduct::create([
                    'store_id'      => $request->store_id,
                    'product_id'    => $request->product_id,
                    'quantity'      => $quantity,
                    'left_quantity' => $quantity,
                    'threshold'     => $threshold,
                    'is_active'     => 1,
                ]);
                $action = 'added';
                $message = 'Product mapped to store successfully';
            }

            InventoryLog::create([
                'product_id' => $storeProduct->product_id,
                'store_id'   => $storeProduct->store_id,
                'user_id'    => auth()->id(),
                'type'       => 'in',
                'action'     => $action,
                'quantity'   => $quantity,
                'remarks'    => $request->remarks,
            ]);

            DB::commit();

            return response()->json([
                'status'  => 200,
                'message' => $message,
                'data'    => new StoreProductResource($storeProduct->load($this->with)),
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();

            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function show(int $id)
    {
        try {
            $storeProduct = StoreProduct::with($this->with)->find($id);

            if (!$storeProduct) {
                return response()->json([
                    'status'  => 404,
                    'message' => 'Store inventory record not found'
                ], 404);
            }

            return response()->json([
                'status'  => 200,
                'message' => 'Store inventory record fetched successfully',
                'data'    => new StoreProductResource($storeProduct)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * The single edit endpoint for a store inventory row.
     *
     * Stock, floor and active flag all move through here, in one transaction,
     * so a caller never has to know which of two endpoints owns which field.
     * Every field is optional; whatever is sent is applied.
     *
     * `quantity` is the new TOTAL stock, not a delta — send 50 and the row
     * holds 50. What has already been issued out of this row is historical
     * fact, so it is preserved and `left_quantity` is recomputed as
     * (new total - already issued). The difference between the old and new
     * total is still written to `inventory_logs` as a movement, because that
     * table records changes rather than balances and reading an absolute out
     * of it would corrupt every running total built on top.
     *
     * When threshold and quantity change together the new stock is checked
     * against the NEW threshold: the request is one edit, so it is the state
     * it leaves behind that has to be valid, not the state it passed through.
     */
    public function update(UpdateStoreProductRequest $request, int $id)
    {
        try {
            $storeProduct = StoreProduct::with($this->with)->find($id);

            if (!$storeProduct) {
                return response()->json([
                    'status'  => 404,
                    'message' => 'Store inventory record not found'
                ], 404);
            }

            $currentQuantity = (float) $storeProduct->quantity;
            $issued = $currentQuantity - (float) $storeProduct->left_quantity;

            $newQuantity = $request->filled('quantity')
                ? (float) $request->quantity
                : $currentQuantity;

            $threshold = $request->filled('threshold')
                ? (float) $request->threshold
                : (float) $storeProduct->threshold;

            // The movement this edit represents. Zero means nothing moved, so
            // it earns no log line.
            $delta = $newQuantity - $currentQuantity;
            $stockMoves = $delta != 0.00;

            $newLeftQuantity = $newQuantity - $issued;

            if ($newQuantity < $threshold) {
                return response()->json([
                    'status'  => 422,
                    'message' => 'Validation failed',
                    'errors'  => [
                        'quantity' => ["The total stock quantity ({$newQuantity}) cannot be lower than this store's minimum quantity of {$threshold}."]
                    ]
                ], 422);
            }

            if ($newLeftQuantity < 0) {
                return response()->json([
                    'status'  => 422,
                    'message' => 'Validation failed',
                    'errors'  => [
                        'quantity' => ["Cannot set the total stock to {$newQuantity}. A total of {$issued} units have already been issued from this store, so the total cannot go below that."]
                    ]
                ], 422);
            }

            DB::beginTransaction();

            $storeProduct->threshold = $threshold;

            if ($request->filled('is_active')) {
                $storeProduct->is_active = (int) $request->is_active;
            }

            if ($stockMoves) {
                $storeProduct->quantity = $newQuantity;
                $storeProduct->left_quantity = $newLeftQuantity;
            }

            $storeProduct->save();

            if ($stockMoves) {
                InventoryLog::create([
                    'product_id' => $storeProduct->product_id,
                    'store_id'   => $storeProduct->store_id,
                    'user_id'    => auth()->id(),
                    'type'       => $delta > 0 ? 'in' : 'out',
                    'action'     => $delta > 0 ? 'replenished' : 'edited',
                    'quantity'   => $delta,
                    'remarks'    => $request->remarks,
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 200,
                'message' => $stockMoves
                    ? 'Store stock quantity updated successfully'
                    : 'Store inventory updated successfully',
                'data'    => new StoreProductResource($storeProduct->fresh($this->with)),
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();

            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Unmap a product from a store. Refused while stock is still on the shelf —
     * removing the row would drop that balance with no movement to explain it.
     */
    public function destroy(int $id)
    {
        try {
            $storeProduct = StoreProduct::find($id);

            if (!$storeProduct) {
                return response()->json([
                    'status'  => 404,
                    'message' => 'Store inventory record not found'
                ], 404);
            }

            if ((float) $storeProduct->left_quantity > 0) {
                return response()->json([
                    'status'  => 422,
                    'message' => "Cannot remove this product from the store while {$storeProduct->left_quantity} units are still in stock. Reduce the quantity to zero first."
                ], 422);
            }

            $storeProduct->delete();

            return response()->json([
                'status'  => 200,
                'message' => 'Product removed from store successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Movement history for one store/product pair.
     */
    public function logs(int $id)
    {
        try {
            $storeProduct = StoreProduct::find($id);

            if (!$storeProduct) {
                return response()->json([
                    'status'  => 404,
                    'message' => 'Store inventory record not found'
                ], 404);
            }

            $logs = InventoryLog::with(['product', 'user'])
                ->where('store_id', $storeProduct->store_id)
                ->where('product_id', $storeProduct->product_id)
                ->latest()
                ->get();

            return response()->json([
                'status'  => 200,
                'message' => 'Store stock logs fetched successfully',
                'data'    => InventoryLogResource::collection($logs)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Dropdown for the service-record form: what a store can actually issue.
     *
     * Strictly above threshold, matching InventoryController@getAvailableProducts
     * — a row sitting exactly at its floor has nothing issuable left.
     */
    public function availableProducts(Request $request)
    {
        // Outside the try/catch: a ValidationException is a Throwable, and
        // catching it below would turn a 422 into a 500.
        $request->validate([
            'store_id' => 'required|integer|exists:stores,id'
        ]);

        try {
            $storeProducts = StoreProduct::with($this->with)
                ->where('store_id', (int) $request->store_id)
                ->where('is_active', 1)
                ->whereColumn('left_quantity', '>', 'threshold')
                ->get()
                ->map(function ($storeProduct) {
                    return [
                        'store_product_id'   => $storeProduct->id,
                        'store_id'           => $storeProduct->store_id,
                        'store_name'         => optional($storeProduct->store)->name,
                        'product_id'         => $storeProduct->product_id,
                        'name'               => optional($storeProduct->product)->name,
                        'sub_category_name'  => optional(optional($storeProduct->product)->subCategory)->name,
                        'category_name'      => optional(optional(optional($storeProduct->product)->subCategory)->category)->name,
                        'left_quantity'      => (float) $storeProduct->left_quantity,
                        'threshold'          => (float) $storeProduct->threshold,
                        'available_quantity' => (float) $storeProduct->left_quantity - (float) $storeProduct->threshold,
                    ];
                })
                ->values();

            return response()->json([
                'status'  => 200,
                'message' => 'Available store products fetched successfully',
                'data'    => $storeProducts
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
