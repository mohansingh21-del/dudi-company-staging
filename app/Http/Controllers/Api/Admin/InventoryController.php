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
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Mail;
use App\Mail\LowStockAlertMail;
use App\Models\User;
use App\Models\Product;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $page = $request->input('page', 1);
            $search = $request->input('search', null);
            $inventories = Inventory::with(['product.subCategory.category']);

            if ($request->filled('search')) {
                $search = $request->search;
                $inventories->whereHas('product', function ($query) use ($search) {
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

    public function store(AddInventoryRequest $request)
    {
        try {
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

            $inventory = Inventory::where('product_id', $request->product_id)->first();

            if ($inventory) {
                DB::beginTransaction();
                $inventory->quantity += $request->quantity;
                $inventory->left_quantity += $request->quantity;
                $inventory->save();

                // Create inventory log entry
                InventoryLog::create([
                    'product_id' => $request->product_id,
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
                    'data' => new InventoryResource($inventory->load('product.subCategory.category'))
                ]);
            }

            DB::beginTransaction();
            $inventory = Inventory::create([
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'left_quantity' => $request->quantity
            ]);

            // Create inventory log entry
            InventoryLog::create([
                'product_id' => $request->product_id,
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
                'data' => new InventoryResource($inventory->load('product.subCategory.category'))
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function assign(AssignInventoryRequest $request)
    {
        DB::beginTransaction();
        try {
            $inventory = Inventory::where('product_id', $request->product_id)->first();

            $availableStock = $inventory ? (float) $inventory->left_quantity : 0.00;
            $quantity = (float) $request->quantity;
            $product = \App\Models\Product::find($request->product_id);
            $minStock = $product ? (float) $product->min_stock : 0.00;

            if ($availableStock - $quantity < $minStock) {
                $productName = optional($product)->name ?? 'Unknown Product';
                $recipients = User::whereHas('roles', function ($query) {
                    $query->whereIn('slug', ['super-admin', 'supervisor']);
                })->get();

                $emails = $recipients->pluck('email')->filter()->toArray();
                if (!empty($emails)) {
                    try {
                        Mail::to($emails)->send(new LowStockAlertMail($productName, $availableStock));
                    } catch (\Throwable $mailError) {
                        \Illuminate\Support\Facades\Log::error("Failed to send low stock email: " . $mailError->getMessage());
                    }
                }

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
                $productName = optional($product)->name ?? 'Unknown Product';
                $recipients = User::whereHas('roles', function ($query) {
                    $query->whereIn('slug', ['super-admin', 'supervisor']);
                })->get();

                $emails = $recipients->pluck('email')->filter()->toArray();
                if (!empty($emails)) {
                    try {
                        Mail::to($emails)->send(new LowStockAlertMail($productName, (float) $inventory->left_quantity));
                    } catch (\Throwable $mailError) {
                        \Illuminate\Support\Facades\Log::error("Failed to send low stock email: " . $mailError->getMessage());
                    }
                }
            }

            $employee = \App\Models\Employee::find($request->employee_id);
            $employeeCode = $employee ? $employee->employee_code : 'Unknown';

            // Create assignment
            $assignment = EmployeeProductAssignment::create([
                'employee_id' => $request->employee_id,
                'product_id' => $request->product_id,
                'issued_date' => $request->issued_date,
                'site_id' => $request->site_id,
                'department_id' => $request->department_id,
                'quantity' => $quantity,
                'remarks' => $request->remarks
            ]);

            // Create inventory log entry
            InventoryLog::create([
                'product_id' => $request->product_id,
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
                'data' => new EmployeeProductAssignmentResource($assignment->load(['employee', 'product.subCategory.category', 'site', 'department']))
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function logs(int $productId)
    {
        try {
            $logs = InventoryLog::with(['product', 'user.roles'])
                ->where('product_id', $productId)
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
            $inventory = Inventory::with([
                'product.subCategory.category',
                'product.assignments' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                },
                'product.assignments.employee',
                'product.assignments.site',
                'product.assignments.department'
            ])->find($id);

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

            $allocationHistory = $product->assignments->map(function ($assignment, $index) {
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
                    'product_name' => $product->name,
                    'category_name' => optional(optional($product->subCategory)->category)->name,
                    'sub_category_name' => optional($product->subCategory)->name,
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
            $assignments = EmployeeProductAssignment::with(['employee', 'product.subCategory.category', 'site', 'department'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => 200,
                'message' => 'Product assignments fetched successfully',
                'data' => EmployeeProductAssignmentResource::collection($assignments)
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
                'file' => 'required|mimes:xlsx,xls,csv'
            ]);

            $import = new InventoryImport;
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

    public function updateQuantity(Request $request, $id)
    {
        try {
            $request->validate([
                'quantity' => 'required|numeric',
                'remarks' => 'nullable|string|max:255'
            ]);

            $inventory = Inventory::find($id);

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
            $inventory->save();

            // Create inventory log entry
            $logType = $request->quantity >= 0 ? 'in' : 'out';
            $logAction = $request->quantity >= 0 ? 'replenished' : 'edited';
            InventoryLog::create([
                'product_id' => $inventory->product_id,
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
                'data' => new InventoryResource($inventory->load('product.subCategory.category'))
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
}
