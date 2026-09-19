<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Concerns\GuardsMasterDeactivation;
use App\Http\Requests\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Http\Request;

/**
 * Form E leave master.
 *
 * The register always prints the same four statutory blocks, so the rows are
 * seeded by migration — there is no create and no delete. An admin edits the
 * annual quota and the active flag; paid/unpaid follows the block.
 */
class LeaveTypeController extends Controller
{
    use GuardsMasterDeactivation;

    public function index(Request $request)
    {
        try {
            $query = LeaveType::onRegister();

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('register_group', 'LIKE', "%{$search}%");
                });
            }

            if ($request->filled('status')) {
                $query->where('is_active', $request->status ? 1 : 0);
            }

            // Register column order, not insertion order, so the list reads the
            // way the printed form does.
            $ordered = $query->get()->sortBy(function ($type) {
                return array_search($type->register_group, array_keys(LeaveType::REGISTER_GROUPS));
            })->values();

            return response()->json([
                'status' => 200,
                'message' => 'LeaveType list fetched successfully',
                'data' => LeaveTypeResource::collection($ordered),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Public, unauthenticated list for leave-apply dropdowns.
     *
     * Active blocks only, in register order. Deliberately omits the annual
     * quota and the active flag — a dropdown does not need the establishment's
     * leave policy, and this endpoint requires no token.
     */
    public function publicList()
    {
        try {
            $types = LeaveType::onRegister()
                ->where('is_active', true)
                ->get()
                ->sortBy(function ($type) {
                    return array_search($type->register_group, array_keys(LeaveType::REGISTER_GROUPS));
                })
                ->values()
                ->map(function ($type) {
                    return [
                        'id' => $type->id,
                        'name' => $type->name,
                        'leave_category' => $type->leave_category,
                        'register_group' => $type->register_group,
                        // So the apply screen can disable a paid block with no
                        // quota rather than let it be picked and then rejected.
                        // Unpaid blocks always come back can_apply = true.
                        'allowed_days' => $type->allowed_days,
                        'can_apply' => $type->canApply(),
                    ];
                });

            return response()->json([
                'status' => 200,
                'message' => 'Leave types fetched successfully',
                'data' => $types,
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
        $leaveType = LeaveType::find($id);

        if (! $leaveType) {
            return response()->json([
                'status' => 404,
                'message' => 'LeaveType not found'
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'data' => new LeaveTypeResource($leaveType)
        ]);
    }

    public function update(UpdateLeaveTypeRequest $request, int $id)
    {
        $leaveType = LeaveType::find($id);

        if (! $leaveType) {
            return response()->json([
                'status' => 404,
                'message' => 'LeaveType not found'
            ], 404);
        }

        $payload = [];

        if ($request->filled('name')) {
            $payload['name'] = $request->name;
        }

        if ($request->has('Annual_limit')) {
            $payload['allowed_days'] = (int) $request->input('Annual_limit', 0);
        }

        // Paid/unpaid is decided by the block, so keep it correct even if an
        // older row was seeded before that rule existed.
        if ($leaveType->register_group) {
            $payload['leave_category'] = LeaveType::categoryForGroup($leaveType->register_group);
        }

        $leaveType->update($payload);

        // The Compensatory Rest quota is the source of the monthly paid rest-day
        // cap, which is memoised. Drop it so a long-lived worker does not keep
        // pricing rest days against the old quota.
        \App\Services\LeaveBalanceService::forgetMonthlyPaidRestDays();

        return response()->json([
            'status' => 200,
            'message' => 'Leave Type updated successfully',
            'data' => new LeaveTypeResource($leaveType->fresh())
        ]);
    }

    public function toggleStatus(Request $request, int $id)
    {
        $leaveType = LeaveType::find($id);

        if (! $leaveType) {
            return response()->json([
                'status' => 404,
                'message' => 'LeaveType not found'
            ], 404);
        }

        $request->validate([
            'status' => 'required|in:0,1'
        ]);


        if ($blocked = $this->blockDeactivation($leaveType, $request->status)) {
            return $blocked;
        }

        $leaveType->is_active = $request->status ? 1 : 0;
        $leaveType->save();

        return response()->json([
            'status' => 200,
            'message' => 'LeaveType status updated successfully'
        ]);
    }
}
