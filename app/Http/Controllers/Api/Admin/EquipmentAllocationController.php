<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AllocateEquipmentRequest;
use App\Services\EquipmentAllocationService;
use Illuminate\Http\Request;

class EquipmentAllocationController extends Controller
{
    /**
     * @var EquipmentAllocationService
     */
    protected $service;

    public function __construct(EquipmentAllocationService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /machine-categories
     * Returns all equipment categories (Excavator, Dumper, etc.).
     */
    public function categories()
    {
        try {
            $result = $this->service->listCategories();

            return response()->json($result, $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * GET /shift-plans/{shift_plan_id}/equipment/available?category_id={id}
     * Returns machines available for allocation.
     */
    public function available(Request $request, $shiftPlanId)
    {
        try {
            $categoryId = $request->query('category_id');

            $result = $this->service->getAvailableMachines($shiftPlanId, $categoryId);

            return response()->json($result, $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * POST /shift-plans/{shift_plan_id}/equipment
     * Allocate a machine to a shift.
     */
    public function allocate(AllocateEquipmentRequest $request, $shiftPlanId)
    {
        try {
            $result = $this->service->allocate($shiftPlanId, $request->validated());

            return response()->json($result, $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * GET /shift-plans/{shift_plan_id}/equipment
     * Returns allocated equipment for a shift (with nesting).
     */
    public function index($shiftPlanId)
    {
        try {
            $result = $this->service->listAllocated($shiftPlanId);

            return response()->json($result, $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * DELETE /shift-plans/{shift_plan_id}/equipment/{allocation_id}
     * Remove a machine allocation (cascades to nested children).
     */
    public function destroy($shiftPlanId, $allocationId)
    {
        try {
            $result = $this->service->remove($shiftPlanId, $allocationId);

            return response()->json($result, $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * GET /shift-plans/{shift_id}/machines
     * Returns allocated equipment for a shift (resolved via shift_id).
     */
    public function getPublicMachines(Request $request, $shiftId)
    {
        try {
            $date = $request->query('date');
            $result = $this->service->listAllocatedByShift($shiftId, $date);

            return response()->json($result, $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }
}


