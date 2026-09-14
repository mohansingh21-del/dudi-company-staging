<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryAlertResource;
use App\Models\Inventory;
use App\Models\InventoryAlert;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The inventory alert feed.
 *
 * Alerts are written by InventoryAlertService as stock moves; this controller
 * only reads them and tracks what has been read. Read state is shared — one
 * admin marking an alert read marks it for everyone.
 *
 * type and severity filters take a comma-separated list, e.g.
 * ?type=low_stock,out_of_stock.
 */
class InventoryAlertController extends Controller
{
    public function index(Request $request)
    {
        try {
            $this->validateFilters($request);

            $alerts = $this->filtered($request)
                ->with(['triggeredBy', 'readBy'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                // Always paginated: the feed only grows.
                ->paginate((int) $request->input('limit', 20));

            return response()->json([
                'status' => 200,
                'message' => 'Inventory alerts fetched successfully.',
                'data' => InventoryAlertResource::collection($alerts->items()),
                'pagination' => [
                    'total' => $alerts->total(),
                    'current_page' => $alerts->currentPage(),
                    'per_page' => $alerts->perPage(),
                    'last_page' => $alerts->lastPage(),
                    'from' => $alerts->firstItem(),
                    'to' => $alerts->lastItem(),
                ]
            ], 200);
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $th) {
            return $this->serverError($th);
        }
    }

    /**
     * Badge and dashboard counts.
     *
     * `open` counts unresolved level alerts; `stock` counts rows straight from
     * the stock table. They normally agree — `stock` also catches rows that
     * never moved through an alerting path, such as stock that was already low
     * before alerts existed.
     */
    public function summary(Request $request)
    {
        try {
            $request->validate(['store_id' => 'nullable|integer']);

            $storeId = $request->filled('store_id') ? (int) $request->store_id : null;

            $forStore = function ($query) use ($storeId) {
                return $storeId === null ? $query : $query->where('store_id', $storeId);
            };

            $unreadByType = array_fill_keys(InventoryAlert::TYPES, 0);
            $unreadCounts = $forStore(InventoryAlert::unread())
                ->selectRaw('type, COUNT(*) as total')
                ->groupBy('type')
                ->pluck('total', 'type');

            foreach ($unreadCounts as $type => $total) {
                $unreadByType[$type] = (int) $total;
            }

            $open = array_fill_keys(InventoryAlert::LEVEL_TYPES, 0);
            $openCounts = $forStore(InventoryAlert::openLevel())
                ->selectRaw('type, COUNT(*) as total')
                ->groupBy('type')
                ->pluck('total', 'type');

            foreach ($openCounts as $type => $total) {
                $open[$type] = (int) $total;
            }

            $stock = Inventory::query()->join('products', 'products.id', '=', 'inventories.product_id');

            if ($storeId !== null) {
                $stock->where('inventories.store_id', $storeId);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Inventory alert summary fetched successfully.',
                'data' => [
                    'unread_count' => array_sum($unreadByType),
                    'unread_by_type' => $unreadByType,
                    'open' => $open,
                    'stock' => [
                        'low_stock' => (clone $stock)
                            ->where('inventories.left_quantity', '>', 0)
                            ->whereColumn('inventories.left_quantity', '<=', 'products.min_stock')
                            ->count(),
                        'out_of_stock' => (clone $stock)
                            ->where('inventories.left_quantity', '<=', 0)
                            ->count(),
                    ],
                ]
            ], 200);
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $th) {
            return $this->serverError($th);
        }
    }

    public function show(int $id)
    {
        try {
            $alert = InventoryAlert::with(['triggeredBy', 'readBy'])->find($id);

            if (!$alert) {
                return $this->notFound();
            }

            return response()->json([
                'status' => 200,
                'message' => 'Inventory alert fetched successfully.',
                'data' => new InventoryAlertResource($alert)
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError($th);
        }
    }

    public function markRead(int $id)
    {
        try {
            $alert = InventoryAlert::find($id);

            if (!$alert) {
                return $this->notFound();
            }

            // Keeps the first reader rather than whoever opened it last.
            if ($alert->read_at === null) {
                $alert->update([
                    'read_at' => now(),
                    'read_by' => auth()->id(),
                ]);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Inventory alert marked as read.',
                'data' => new InventoryAlertResource($alert->fresh(['triggeredBy', 'readBy']))
            ], 200);
        } catch (\Throwable $th) {
            return $this->serverError($th);
        }
    }

    /**
     * Marks every unread alert matching the same filters as index — so "mark
     * all read" on a filtered screen only touches what that screen shows.
     */
    public function markAllRead(Request $request)
    {
        try {
            $this->validateFilters($request);

            $marked = $this->filtered($request)
                ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'read_by' => auth()->id(),
                    'updated_at' => now(),
                ]);

            return response()->json([
                'status' => 200,
                'message' => "{$marked} inventory alert(s) marked as read.",
                'data' => ['marked' => $marked]
            ], 200);
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $th) {
            return $this->serverError($th);
        }
    }

    /**
     * @param  Request  $request
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function filtered(Request $request)
    {
        $query = InventoryAlert::query();

        if ($types = $this->csv($request, 'type')) {
            $query->whereIn('type', $types);
        }

        if ($severities = $this->csv($request, 'severity')) {
            $query->whereIn('severity', $severities);
        }

        foreach (['store_id', 'product_id', 'inventory_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, (int) $request->input($column));
            }
        }

        // open/resolved only mean something for level alerts, so either one
        // narrows the feed to low_stock and out_of_stock.
        if ($request->input('status') === 'open') {
            $query->openLevel();
        } elseif ($request->input('status') === 'resolved') {
            $query->whereIn('type', InventoryAlert::LEVEL_TYPES)->whereNotNull('resolved_at');
        }

        if ($request->input('read') === 'unread') {
            $query->whereNull('read_at');
        } elseif ($request->input('read') === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('message', 'LIKE', "%{$search}%")
                    ->orWhere('product_name', 'LIKE', "%{$search}%")
                    ->orWhere('store_name', 'LIKE', "%{$search}%")
                    ->orWhere('reference', 'LIKE', "%{$search}%");
            });
        }

        return $query;
    }

    protected function validateFilters(Request $request)
    {
        $request->validate([
            'store_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'inventory_id' => 'nullable|integer',
            'status' => 'nullable|in:open,resolved',
            'read' => 'nullable|in:read,unread',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'limit' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
        ]);

        foreach (['type' => InventoryAlert::TYPES, 'severity' => InventoryAlert::SEVERITY_LEVELS] as $field => $allowed) {
            $unknown = array_diff($this->csv($request, $field), $allowed);

            if (!empty($unknown)) {
                throw ValidationException::withMessages([
                    $field => ["Unknown {$field}: " . implode(', ', $unknown) . '. Allowed: ' . implode(', ', $allowed) . '.']
                ]);
            }
        }
    }

    /**
     * A filter given as "a,b" or as a[]=a&a[]=b.
     *
     * @return array
     */
    protected function csv(Request $request, $field)
    {
        $value = $request->input($field);

        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('trim', $values), 'strlen'));
    }

    protected function validationFailed(ValidationException $e)
    {
        return response()->json([
            'status' => 422,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    }

    protected function notFound()
    {
        return response()->json([
            'status' => 404,
            'message' => 'Inventory alert not found.'
        ], 404);
    }

    protected function serverError(\Throwable $th)
    {
        return response()->json([
            'status' => 500,
            'message' => $th->getMessage()
        ], 500);
    }
}
