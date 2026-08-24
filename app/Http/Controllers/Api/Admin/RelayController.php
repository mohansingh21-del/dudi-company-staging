<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Relay;
use App\Http\Requests\StoreRelayRequest;
use App\Http\Requests\UpdateRelayRequest;
use App\Http\Resources\RelayResource;

class RelayController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);
            $query = Relay::query();

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where('name', 'LIKE', "%{$search}%");
            }

            $relays = $query->latest()->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Relay list fetched successfully',
                'data' => RelayResource::collection($relays),
                'pagination' => [
                    'current_page' => $relays->currentPage(),
                    'last_page' => $relays->lastPage(),
                    'per_page' => $relays->perPage(),
                    'total' => $relays->total(),
                    'from' => $relays->firstItem(),
                    'to' => $relays->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function store(StoreRelayRequest $request)
    {
        try {
            $relay = Relay::create([
                'name' => $request->name,
                'is_rotating' => $request->input('is_rotating', true),
                'is_active' => $request->input('is_active', true),
            ]);

            if ($request->filled('shift_id') && $relay->is_rotating) {
                $this->assignShiftToRelay($relay, $request->shift_id);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Relay created successfully',
                'data' => new RelayResource($relay)
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
        $relay = Relay::find($id);

        if (!$relay) {
            return response()->json([
                'status' => 404,
                'message' => 'Relay not found'
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'data' => new RelayResource($relay)
        ]);
    }

    public function update(UpdateRelayRequest $request, int $id)
    {
        try {
            $relay = Relay::find($id);

            if (!$relay) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Relay not found'
                ], 404);
            }

            if ($request->filled('shift_id') && (int) $request->shift_id === (int) $relay->current_shift_id) {
                return response()->json([
                    'status' => 422,
                    'message' => 'This shift is already assigned to this relay.'
                ], 422);
            }

            $relay->update([
                'name' => $request->name,
                'is_rotating' => $request->input('is_rotating', $relay->is_rotating),
                'is_active' => $request->input('is_active', $relay->is_active),
            ]);

            if ($request->filled('shift_id') && $relay->is_rotating) {
                $this->assignShiftToRelay($relay, $request->shift_id);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Relay updated successfully',
                'data' => new RelayResource($relay)
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
            $relay = Relay::find($id);

            if (!$relay) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Relay not found'
                ], 404);
            }

            // Optional check: Check if employees are assigned to this relay before deleting
            $hasEmployees = \App\Models\Employee::where('relay_shift', 'relay_' . $id)->exists(); // or check dynamic relay_id later
            if ($hasEmployees) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Cannot delete relay as it is assigned to employees.'
                ], 422);
            }

            $relay->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Relay deleted successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(Request $request, int $id)
    {
        $relay = Relay::find($id);

        if (!$relay) {
            return response()->json([
                'status' => 404,
                'message' => 'Relay not found'
            ], 404);
        }

        // Auto toggle status (true -> false, false -> true)
        $relay->is_active = !$relay->is_active;
        $relay->save();

        $statusStr = $relay->is_active ? 'activated' : 'deactivated';

        return response()->json([
            'status' => 200,
            'message' => "Relay status updated successfully. Relay is now {$statusStr}."
        ]);
    }

    public function getPublicRelays(Request $request)
    {
        try {
            $limit = $request->input('limit', null);
            $query = Relay::where('is_active', 1)->select('id', 'name', 'is_rotating', 'is_active');

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where('name', 'LIKE', "%{$search}%");
            }

            if ($limit) {
                $relays = $query->latest()->paginate($limit);
                $data = $relays->items();
                $pagination = [
                    'current_page' => $relays->currentPage(),
                    'last_page' => $relays->lastPage(),
                    'per_page' => $relays->perPage(),
                    'total' => $relays->total(),
                    'from' => $relays->firstItem(),
                    'to' => $relays->lastItem(),
                ];
            } else {
                $data = $query->latest()->get();
                $pagination = null;
            }

            return response()->json([
                'status' => 200,
                'message' => 'Relay list fetched successfully',
                'data' => $data,
                'pagination' => $pagination
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Create/update this week's relay-shift mapping. Assigning a shift named
     * "General" pins the relay out of the auto-rotation cycle going forward,
     * since a manual General assignment means the relay is no longer rotating.
     */
    private function assignShiftToRelay(Relay $relay, $shiftId)
    {
        $today = now();
        $weekStart = $today->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
        $weekEnd = $today->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(6)->toDateString();

        \App\Models\RelayShiftMapping::updateOrCreate(
            [
                'week_start_date' => $weekStart,
                'relay_id' => $relay->id,
            ],
            [
                'week_end_date' => $weekEnd,
                'shift_id' => $shiftId,
            ]
        );

        $shift = \App\Models\Shift::find($shiftId);
        if ($shift && stripos($shift->shift_name, 'general') !== false) {
            $relay->update(['is_rotating' => false]);
        }
    }
}
