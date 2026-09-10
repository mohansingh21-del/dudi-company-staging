<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\EmployeeProductAssignment;
use App\Http\Requests\AddInventoryRequest;
use App\Http\Requests\AssignInventoryRequest;
use App\Http\Resources\InventoryResource;
use App\Http\Resources\InventoryLogResource;
use App\Http\Resources\EmployeeProductAssignmentResource;
use App\Imports\InventoryImport;
use App\Services\Concerns\SendsLowStockAlert;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Stock, one row per (store, product).
 *
 * There is no store-less stock and no default store: a row is always somewhere.
 * The floor a deduction may not cross is products.min_stock, set once on the
 * product and applied identically in every store that carries it.
 *
 * store_id is required on every write. On the reads it is an optional filter —
 * leaving it off spans every store, so a screen that has not been taught about
 * stores yet still renders.
 */
class InventoryController extends Controller
{
    use SendsLowStockAlert;

    /**
     * Standard eager loads. The product carries min_stock, which is the floor
     * for every row that points at it.
     *
     * @var array
     */
    protected $with = ['store', 'product.subCategory.category'];

    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $inventories = Inventory::with($this->with);

            if ($request->filled('store_id')) {
                $inventories->where('store_id', (int) $request->store_id);
            }

            if ($request->filled('product_id')) {
                $inventories->where('product_id', (int) $request->product_id);
            }

            // Stock sitting at or under its product's floor. The floor lives on
            // products, so this has to reach across the join rather than
            // compare two columns of this table.
            if ($request->boolean('low_stock')) {
                $inventories->whereHas('product', function ($query) {
                    $query->whereColumn('products.min_stock', '>=', 'inventories.left_quantity');
                });
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $inventories->where(function ($query) use ($search) {
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

            if ($limit) {
                $paginated = $inventories->paginate($limit, ['*'], 'page', $page);
                $response = [
                    'data' => InventoryResource::collection($paginated->items()),
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
                $all = $inventories->get();
                $response = [
                    'data' => InventoryResource::collection($all),
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
                'message' => 'Inventory list fetched successfully.',
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

    /**
     * Add stock for a product at a store.
     *
     * Re-posting a pair that already exists replenishes it rather than failing —
     * the unique(store_id, product_id) index is a safety net, not the intended
     * error path. The same product can be stocked at as many stores as needed.
     */
    public function store(AddInventoryRequest $request)
    {
        try {
            $storeId = (int) $request->store_id;
            $product = \App\Models\Product::find($request->product_id);

            if ($product && (float) $request->quantity < (float) $product->min_stock) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => [
                        'quantity' => ["The quantity must be at least {$product->min_stock} (minimum stock level for this product)."]
                    ]
                ], 422);
            }

            $inventory = Inventory::where('store_id', $storeId)
                ->where('product_id', $request->product_id)
                ->first();

            if ($inventory) {
                DB::beginTransaction();
                $inventory->quantity += $request->quantity;
                $inventory->left_quantity += $request->quantity;
                $inventory->save();

                // Create inventory log entry
                InventoryLog::create([
                    'product_id' => $request->product_id,
                    'store_id' => $storeId,
                    'user_id' => auth()->id(),
                    'type' => 'in',
                    'action' => 'replenished',
                    'quantity' => $request->quantity,
                    'remarks' => $request->remarks ?? 'Replenished stock quantity'
                ]);

                DB::commit();

                return response()->json([
                    'status' => 200,
                    'message' => 'Product stock added to inventory successfully',
                    'data' => new InventoryResource($inventory->load($this->with))
                ]);
            }

            DB::beginTransaction();
            $inventory = Inventory::create([
                'store_id' => $storeId,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'left_quantity' => $request->quantity,
                'is_active' => 1
            ]);

            // Create inventory log entry
            InventoryLog::create([
                'product_id' => $request->product_id,
                'store_id' => $storeId,
                'user_id' => auth()->id(),
                'type' => 'in',
                'action' => 'added',
                'quantity' => $request->quantity,
                'remarks' => $request->remarks
            ]);

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'Product stock added to inventory successfully',
                'data' => new InventoryResource($inventory->load($this->with))
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Issue stock to an employee out of a named store.
     */
    public function assign(AssignInventoryRequest $request)
    {
        DB::beginTransaction();
        try {
            $storeId = (int) $request->store_id;

            $inventory = Inventory::with($this->with)
                ->where('store_id', $storeId)
                ->where('product_id', $request->product_id)
                ->first();

            if (!$inventory) {
                DB::rollBack();
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => [
                        'product_id' => ['This product is not stocked at the selected store.']
                    ]
                ], 422);
            }

            $availableStock = (float) $inventory->left_quantity;
            $quantity = (float) $request->quantity;
            $product = $inventory->product;
            $minStock = $product ? (float) $product->min_stock : 0.00;
            $productName = optional($product)->name ?? 'Unknown Product';
            $storeName = optional($inventory->store)->name;

            if ($availableStock - $quantity < $minStock) {
                $this->sendLowStockAlert($productName, $availableStock, $storeName);

                DB::rollBack();

                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => [
                        'quantity' => ["Minimum quantity reached. Please update quantity of product so that assigning product to employee can continue."]
                    ]
                ], 422);
            }

            // Deduct inventory left_quantity
            $inventory->left_quantity -= $quantity;
            $inventory->save();

            // Send low stock alert if left_quantity drops to or below min_stock
            if ((float) $inventory->left_quantity <= $minStock) {
                $this->sendLowStockAlert($productName, (float) $inventory->left_quantity, $storeName);
            }

            $employee = \App\Models\Employee::find($request->employee_id);
            $employeeCode = $employee ? $employee->employee_code : 'Unknown';

            // Create assignment
            $assignment = EmployeeProductAssignment::create([
                'employee_id' => $request->employee_id,
                'product_id' => $request->product_id,
                'store_id' => $storeId,
                'issued_date' => $request->issued_date,
                'site_id' => $request->site_id,
                'department_id' => $request->department_id,
                'quantity' => $quantity,
                'remarks' => $request->remarks
            ]);

            // Create inventory log entry
            InventoryLog::create([
                'product_id' => $request->product_id,
                'store_id' => $storeId,
                'user_id' => auth()->id(),
                'type' => 'out',
                'action' => 'assigned',
                'quantity' => -$quantity,
                'remarks' => "Product assigned to employee Code: {$employeeCode}" . ($request->remarks ? " - {$request->remarks}" : "")
            ]);

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'Product assigned to employee successfully',
                'data' => new EmployeeProductAssignmentResource($assignment->load(['employee', 'product.subCategory.category', 'store', 'site', 'department']))
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Movement history for one stock row.
     *
     * Keyed by inventory id rather than product id: the same product moves
     * independently at every store that carries it, and the log records those
     * movements against (product_id, store_id).
     */
    public function logs(int $id)
    {
        try {
            $inventory = Inventory::find($id);

            if (!$inventory) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Inventory record not found.'
                ], 404);
            }

            $logs = InventoryLog::with(['product', 'store', 'user.roles'])
                ->where('product_id', $inventory->product_id)
                ->where('store_id', $inventory->store_id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => 200,
                'message' => 'Inventory logs fetched successfully',
                'data' => InventoryLogResource::collection($logs)
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
        try {
            $inventory = Inventory::with($this->with)->find($id);

            if (!$inventory) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Inventory record not found.'
                ], 404);
            }

            $product = $inventory->product;

            if (!$product) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Associated product not found.'
                ], 404);
            }

            // Scoped to this row's store: the same product issued from another
            // store came off a different stock row and does not belong here.
            $assignments = EmployeeProductAssignment::with(['employee', 'site', 'department'])
                ->where('product_id', $inventory->product_id)
                ->where('store_id', $inventory->store_id)
                ->orderBy('created_at', 'desc')
                ->get();

            $allocationHistory = $assignments->map(function ($assignment, $index) {
                return [
                    'sr_no' => $index + 1,
                    'employee_name' => optional($assignment->employee)->name,
                    'employee_code' => optional($assignment->employee)->employee_code,
                   'site_name' => optional($assignment->site)->site_name ?? optional($assignment->site)->name,
                   'department_name' => optional($assignment->department)->name,
                   'quantity_assigned' => (float) $assignment->quantity,
                    'issued_date' => $assignment->issued_date ? $assignment->issued_date->format('d M Y') : null,
                ];
            });

            return response()->json([
                'status' => 200,
                'message' => 'Product details fetched successfully.',
                'data' => [
                    'id' => $inventory->id,
                    'store_id' => $inventory->store_id,
                    'store_name' => optional($inventory->store)->name,
                    'product_name' => $product->name,
                    'category_name' => optional(optional($product->subCategory)->category)->name,
                    'sub_category_name' => optional($product->subCategory)->name,
                    'min_stock' => (float) $product->min_stock,
                    'available_stock' => (float) $inventory->left_quantity,
                    'allocation_history' => $allocationHistory
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function assignments(Request $request)
    {
        try {
            $assignments = EmployeeProductAssignment::with(['employee', 'product.subCategory.category', 'store', 'site', 'department'])
                ->orderBy('created_at', 'desc');

            if ($request->filled('store_id')) {
                $assignments->where('store_id', (int) $request->store_id);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Product assignments fetched successfully',
                'data' => EmployeeProductAssignmentResource::collection($assignments->get())
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function bulkUpload(Request $request)
    {
        try {
            $request->validate([
                'store_id' => 'required|integer|exists:stores,id',
                'file' => 'required|mimes:xlsx,xls,csv'
            ]);

            $import = new InventoryImport((int) $request->store_id);
            Excel::import($import, $request->file('file'));

            $errors = $import->getErrors();
            $warnings = $import->getWarnings();
            $successCount = $import->getSuccessCount();

            if (count($errors) > 0) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Excel validation failed.',
                    'errors' => $errors
                ], 422);
            }

            $responseData = [
                'status' => 200,
                'message' => "Successfully imported {$successCount} products into inventory."
            ];

            if (count($warnings) > 0) {
                $responseData['warnings'] = $warnings;
            }

            return response()->json($responseData);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Adjust one stock row.
     *
     * `quantity` is a DELTA, not a new total — send 5 to add five, -5 to take
     * five off. The store is not a parameter: it is whichever store the row
     * belongs to.
     */
    public function updateQuantity(Request $request, $id)
    {
        try {
            $request->validate([
                'quantity' => 'required|numeric',
                'is_active' => 'nullable|boolean',
                'remarks' => 'nullable|string|max:255'
            ]);

            $inventory = Inventory::with($this->with)->find($id);

            if (!$inventory) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => [
                        'id' => ['Inventory record not found.']
                    ]
                ], 422);
            }

            $product = $inventory->product;
            $minStock = $product ? (float) $product->min_stock : 0.00;
            $newQuantity = (float) $inventory->quantity + (float) $request->quantity;
            $newLeftQuantity = (float) $inventory->left_quantity + (float) $request->quantity;

            if ($newQuantity < $minStock) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => [
                        'quantity' => ["The resulting total stock quantity ({$newQuantity}) cannot be lower than the product's minimum stock of {$minStock}."]
                    ]
                ], 422);
            }

            if ($newLeftQuantity < 0) {
                $assigned = (float) $inventory->quantity - (float) $inventory->left_quantity;
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => [
                        'quantity' => ["Cannot reduce quantity. A total of {$assigned} units of this product are already assigned to employees, which exceeds the proposed total stock of {$newQuantity}."]
                    ]
                ], 422);
            }

            DB::beginTransaction();

            $inventory->quantity = $newQuantity;
            $inventory->left_quantity = $newLeftQuantity;

            if ($request->filled('is_active')) {
                $inventory->is_active = (int) $request->is_active;
            }

            $inventory->save();

            // Create inventory log entry
            $logType = $request->quantity >= 0 ? 'in' : 'out';
            $logAction = $request->quantity >= 0 ? 'replenished' : 'edited';
            InventoryLog::create([
                'product_id' => $inventory->product_id,
                'store_id' => $inventory->store_id,
                'user_id' => auth()->id(),
                'type' => $logType,
                'action' => $logAction,
                'quantity' => $request->quantity,
                'remarks' => $request->remarks ?? ($logType === 'in' ? 'Replenished stock quantity' : 'Decreased stock quantity')
            ]);

            DB::commit();

            return response()->json([
                'status' => 200,
                'message' => 'Product quantity updated successfully in inventory',
                'data' => new InventoryResource($inventory->fresh($this->with))
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 500,
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
            $inventory = Inventory::find($id);

            if (!$inventory) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Inventory record not found.'
                ], 404);
            }

            if ((float) $inventory->left_quantity > 0) {
                return response()->json([
                    'status' => 422,
                    'message' => "Cannot remove this product from the store while {$inventory->left_quantity} units are still in stock. Reduce the quantity to zero first."
                ], 422);
            }

            $inventory->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Product removed from store successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Dropdown for the service-record form: what can actually be issued.
     *
     * "Available" means left_quantity is strictly above the product's min_stock,
     * matching the rule InventoryStockService applies on deduction. A row
     * sitting exactly at min_stock has stock on the shelf but nothing issuable.
     *
     * Keyed by inventory_id, which is what the form posts back — the same
     * product at two stores is two separate options.
     */
    public function getAvailableProducts(Request $request)
    {
        try {
            // Joined rather than a whereHas: min_stock is needed for the
            // comparison, the ordering and the payload, so one join serves all
            // three and keeps a single reference to products in the query.
            $query = Inventory::with($this->with)
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->where('inventories.is_active', 1)
                ->where('products.is_active', 1)
                ->whereColumn('inventories.left_quantity', '>', 'products.min_stock')
                ->orderBy('products.name', 'asc')
                ->select('inventories.*');

            if ($request->filled('store_id')) {
                $query->where('inventories.store_id', (int) $request->store_id);
            }

            // Optional Search on product / sub category / category / store name
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('product', function ($pq) use ($search) {
                        $pq->where('products.name', 'LIKE', "%{$search}%")
                            ->orWhereHas('subCategory', function ($sq) use ($search) {
                                $sq->where('name', 'LIKE', "%{$search}%")
                                    ->orWhereHas('category', function ($cq) use ($search) {
                                        $cq->where('name', 'LIKE', "%{$search}%");
                                    });
                            });
                    })->orWhereHas('store', function ($sq) use ($search) {
                        $sq->where('name', 'LIKE', "%{$search}%");
                    });
                });
            }

            $format = function ($inventory) {
                $product = $inventory->product;
                $leftQuantity = (float) $inventory->left_quantity;
                $minStock = (float) optional($product)->min_stock;

                return [
                    'inventory_id'       => $inventory->id,
                    'store_id'           => $inventory->store_id,
                    'store_name'         => optional($inventory->store)->name,
                    'product_id'         => $inventory->product_id,
                    'name'               => optional($product)->name,
                    'sub_category_name'  => optional(optional($product)->subCategory)->name,
                    'category_name'      => optional(optional(optional($product)->subCategory)->category)->name,
                    'left_quantity'      => $leftQuantity,
                    'min_stock'          => $minStock,
                    'available_quantity' => $leftQuantity - $minStock,
                ];
            };

            if ($request->filled('limit')) {
                $inventories = $query->paginate($request->limit);

                return response()->json([
                    'status' => 200,
                    'message' => 'Available products fetched successfully',
                    'data' => collect($inventories->items())->map($format)->values()->toArray(),
                    'pagination' => [
                        'current_page' => $inventories->currentPage(),
                        'last_page' => $inventories->lastPage(),
                        'per_page' => $inventories->perPage(),
                        'total' => $inventories->total(),
                        'from' => $inventories->firstItem(),
                        'to' => $inventories->lastItem(),
                    ]
                ]);
            }

            $inventories = $query->get();

            return response()->json([
                'status' => 200,
                'message' => 'Available products fetched successfully',
                'data' => $inventories->map($format)->values()->toArray()
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
