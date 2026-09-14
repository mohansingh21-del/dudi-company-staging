<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\EmployeeProductAssignment;
use App\Http\Requests\AddInventoryRequest;
use App\Http\Requests\AssignInventoryRequest;
use App\Http\Resources\InventoryResource;
use App\Http\Resources\InventoryLogResource;
use App\Http\Resources\EmployeeProductAssignmentResource;
use App\Imports\InventoryImport;
use App\Services\InventoryAlertService;
use App\Services\InventoryStockService;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Stock, one row per (store, product).
 *
 * There is no store-less stock and no default store: a row is always somewhere.
 * Stock can be issued down to zero and no further. products.min_stock, set once
 * on the product and applied identically in every store that carries it, is a
 * warning line: crossing it raises an inventory alert but never blocks.
 *
 * store_id is required on every write. On the reads it is an optional filter —
 * leaving it off spans every store, so a screen that has not been taught about
 * stores yet still renders.
 */
class InventoryController extends Controller
{
    /**
     * Standard eager loads. The product carries min_stock, the low-stock line
     * for every row that points at it.
     *
     * @var array
     */
    protected $with = ['store', 'product.subCategory.category'];

    /**
     * @var InventoryAlertService
     */
    protected $alerts;

    public function __construct(InventoryAlertService $alerts)
    {
        $this->alerts = $alerts;
    }

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

            if ($request->filled('sub_category_id')) {
                $subCategoryId = (int) $request->sub_category_id;
                $inventories->whereHas('product', function ($query) use ($subCategoryId) {
                    $query->where('sub_category_id', $subCategoryId);
                });
            }

            // Category is a grandparent here — stock points at a product, which
            // points at a sub-category, which points at the category.
            if ($request->filled('category_id')) {
                $categoryId = (int) $request->category_id;
                $inventories->whereHas('product.subCategory', function ($query) use ($categoryId) {
                    $query->where('category_id', $categoryId);
                });
            }

            // Stock sitting at or under its product's min_stock, which lives on
            // products, so this has to reach across the join rather than
            // compare two columns of this table.
            if ($request->boolean('low_stock')) {
                $inventories->whereHas('product', function ($query) {
                    $query->whereColumn('products.min_stock', '>=', 'inventories.left_quantity');
                });
            }

            // The Stock Status dropdown. Three states that do not overlap and
            // together cover every row, so the filter never hides stock the
            // user would expect to see under some other option:
            //   out_of_stock — nothing left on the shelf at all
            //   low_stock    — some left, but at or under min_stock (still issuable)
            //   in_stock     — above min_stock
            if ($request->filled('stock_status')) {
                switch ($request->stock_status) {
                    case 'out_of_stock':
                        $inventories->where('left_quantity', '<=', 0);
                        break;

                    case 'low_stock':
                        $inventories->where('left_quantity', '>', 0)
                            ->whereHas('product', function ($query) {
                                $query->whereColumn('products.min_stock', '>=', 'inventories.left_quantity');
                            });
                        break;

                    case 'in_stock':
                        $inventories->whereHas('product', function ($query) {
                            $query->whereColumn('products.min_stock', '<', 'inventories.left_quantity');
                        });
                        break;
                }
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
     * This is the only way stock goes up — there is no edit. Re-posting a pair
     * that already exists adds the new quantity on top rather than failing; the
     * unique(store_id, product_id) index is a safety net, not the intended error
     * path. The same product can be stocked at as many stores as needed.
     *
     * min_stock is only checked when a store first stocks a product. A top-up
     * can never make the position worse, so any positive quantity is accepted —
     * including one that leaves a below-floor row still under min_stock.
     */
    public function store(AddInventoryRequest $request)
    {
        try {
            $storeId = (int) $request->store_id;

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

                $this->alerts->stockAdded($inventory, (float) $request->quantity, false, 'manual_add', auth()->id(), $request->remarks);

                DB::commit();

                return response()->json([
                    'status' => 200,
                    'message' => 'Product stock added to inventory successfully',
                    'data' => new InventoryResource($inventory->load($this->with))
                ]);
            }

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

            $this->alerts->stockAdded($inventory, (float) $request->quantity, true, 'manual_add', auth()->id(), $request->remarks);

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
            $productName = optional($inventory->product)->name ?? 'Unknown Product';
            $storeName = optional($inventory->store)->name;

            // Only an empty shelf stops an assignment. Dropping under
            // min_stock is allowed and raises an alert below.
            if ($quantity > $availableStock) {
                DB::rollBack();

                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => [
                        'quantity' => [InventoryStockService::insufficientStockMessage($productName, $storeName, $availableStock)]
                    ]
                ], 422);
            }

            $employee = \App\Models\Employee::find($request->employee_id);
            $employeeCode = $employee ? $employee->employee_code : 'Unknown';

            // Deduct inventory left_quantity
            $inventory->left_quantity -= $quantity;
            $inventory->save();

            $this->alerts->syncStockLevel($inventory, 'assignment', auth()->id(), "Employee Code: {$employeeCode}");

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
            $assignments = EmployeeProductAssignment::with(['employee.site', 'employee.department', 'site', 'department'])
                ->where('product_id', $inventory->product_id)
                ->where('store_id', $inventory->store_id)
                ->orderBy('created_at', 'desc')
                ->get();

            $allocationHistory = $assignments->map(function ($assignment, $index) {
                // Fall back to the employee's own site/department when the
                // assignment wasn't given one explicitly (site_id is optional
                // on assignment).
                $site = $assignment->site ?? optional($assignment->employee)->site;
                $department = $assignment->department ?? optional($assignment->employee)->department;

                return [
                    'sr_no' => $index + 1,
                    'employee_name' => optional($assignment->employee)->name,
                    'employee_code' => optional($assignment->employee)->employee_code,
                   'site_name' => optional($site)->site_name ?? optional($site)->name,
                   'department_name' => optional($department)->name,
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

    /**
     * Bulk-add stock from a sheet.
     *
     * The sheet may carry a store_name column, letting one file stock several
     * stores at once. store_id on the request is then only the fallback for
     * rows that name no store, which is why it is no longer required — but a
     * sheet without the column and an upload without the field leaves those
     * rows homeless, and each is reported as a row error.
     */
    public function bulkUpload(Request $request)
    {
        try {
            $request->validate([
                'store_id' => 'nullable|integer|exists:stores,id',
                'file' => 'required|mimes:xlsx,xls,csv'
            ]);

            $import = new InventoryImport($request->input('store_id'));
            Excel::import($import, $request->file('file'));

            $errors = $import->getErrors();
            $warnings = $import->getWarnings();
            $successCount = $import->getSuccessCount();
            $storeCount = $import->getStoreCount();

            // Rows are committed one at a time, so a file that fails
            // validation may still have imported some — announce those.
            if ($successCount > 0) {
                $this->alerts->bulkImported($successCount, $storeCount, count($errors), auth()->id());
            }

            if (count($errors) > 0) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Excel validation failed.',
                    'errors' => $errors
                ], 422);
            }

            $responseData = [
                'status' => 200,
                'message' => "Successfully imported {$successCount} products into inventory across {$storeCount} store(s)."
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
                    'message' => "Cannot remove this product from the store while {$inventory->left_quantity} units are still in stock."
                ], 422);
            }

            $inventory->delete();

            $this->alerts->productRemoved($inventory, auth()->id());

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
     * "Available" means anything left on the shelf, matching the rule
     * InventoryStockService applies on deduction. Low stock is still issuable,
     * so only empty rows drop out; is_low_stock flags the ones running short.
     *
     * Keyed by inventory_id, which is what the form posts back — the same
     * product at two stores is two separate options.
     */
    public function getAvailableProducts(Request $request)
    {
        try {
            // Joined rather than a whereHas: products is needed for the active
            // filter, the ordering and the payload, so one join serves all
            // three and keeps a single reference to products in the query.
            $query = Inventory::with($this->with)
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->where('inventories.is_active', 1)
                ->where('products.is_active', 1)
                ->where('inventories.left_quantity', '>', 0)
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
                return $this->formatStockRow($inventory);
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

    /**
     * Every product carried in inventory, out-of-stock ones included — for a
     * filter dropdown where "not carried anywhere" and "carried but empty"
     * need to stay distinguishable options rather than both vanishing.
     *
     * Distinct by product, not by inventory row: the same product stocked at
     * three stores is still one dropdown entry.
     */
    public function getInventoryProducts(Request $request)
    {
        try {
            $query = Product::query()
                ->where('is_active', 1)
                ->whereHas('inventories', function ($q) use ($request) {
                    $q->where('is_active', 1);

                    if ($request->filled('store_id')) {
                        $q->where('store_id', (int) $request->store_id);
                    }
                })
                ->orderBy('name', 'asc');

            if ($request->filled('search')) {
                $query->where('name', 'LIKE', "%{$request->search}%");
            }

            return response()->json([
                'status' => 200,
                'message' => 'Inventory products fetched successfully',
                'data' => $query->get(['id', 'name', 'min_stock']),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Everything stocked at one store, for a store-then-product picker.
     *
     * Unlike getAvailableProducts this does not hide empty rows — a store
     * screen that silently dropped them would read as "we do not carry that",
     * when the truth is "we carry it and it needs reordering". Each row says so
     * through is_available instead, and ?only_available=true narrows it to the
     * issuable ones.
     */
    public function getStoreProducts(Request $request, int $storeId)
    {
        try {
            $store = \App\Models\Store::find($storeId);

            if (!$store) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Store not found.'
                ], 404);
            }

            // Joined for the same reason as getAvailableProducts: products is
            // wanted for the ordering and the payload either way.
            $query = Inventory::with($this->with)
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->where('inventories.store_id', $store->id)
                ->where('inventories.is_active', 1)
                ->where('products.is_active', 1)
                ->orderBy('products.name', 'asc')
                ->select('inventories.*');

            if ($request->boolean('only_available')) {
                $query->where('inventories.left_quantity', '>', 0);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('products.name', 'LIKE', "%{$search}%")
                        ->orWhereHas('product.subCategory', function ($sq) use ($search) {
                            $sq->where('name', 'LIKE', "%{$search}%")
                                ->orWhereHas('category', function ($cq) use ($search) {
                                    $cq->where('name', 'LIKE', "%{$search}%");
                                });
                        });
                });
            }

            $meta = [
                'store_id' => $store->id,
                'store_name' => $store->name,
            ];

            if ($request->filled('limit')) {
                $paginated = $query->paginate($request->limit);

                return response()->json([
                    'status' => 200,
                    'message' => 'Store products fetched successfully',
                    'store' => $meta,
                    'data' => collect($paginated->items())
                        ->map(fn ($inventory) => $this->formatStockRow($inventory))
                        ->values()
                        ->toArray(),
                    'pagination' => [
                        'total' => $paginated->total(),
                        'current_page' => $paginated->currentPage(),
                        'per_page' => $paginated->perPage(),
                        'last_page' => $paginated->lastPage(),
                        'from' => $paginated->firstItem(),
                        'to' => $paginated->lastItem(),
                    ]
                ]);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Store products fetched successfully',
                'store' => $meta,
                'data' => $query->get()
                    ->map(fn ($inventory) => $this->formatStockRow($inventory))
                    ->values()
                    ->toArray()
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * One stock row as the product pickers want it: keyed by inventory_id,
     * which is what those forms post back, with min_stock carried alongside so
     * the caller can flag the rows running low.
     *
     * @param  \App\Models\Inventory  $inventory
     * @return array
     */
    protected function formatStockRow($inventory)
    {
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
            // Everything on the shelf can be issued; min_stock only flags it.
            'available_quantity' => max(0, $leftQuantity),
            'is_available'       => $leftQuantity > 0,
            'is_low_stock'       => $leftQuantity <= $minStock,
        ];
    }
}
