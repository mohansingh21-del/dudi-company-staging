<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BorrowEmployeeRequest;
use App\Http\Requests\RemoveDeploymentRequest;
use App\Services\WorkforceDeploymentService;
use Illuminate\Http\Request;

class WorkforceDeploymentController extends Controller
{
    /**
     * @var WorkforceDeploymentService
     */
    protected $service;

    public function __construct(WorkforceDeploymentService $service)
    {
        $this->service = $service;
    }

    /**
     * POST /shift-plans/{shift_plan_id}/workforce/load-relay
     *
     * BR-SHFT-008: Auto-load relay workforce for the shift.
     * Idempotent — safe to call multiple times.
     *
     * Optional body: { "relay_shift": "relay_1" }
     * If relay_shift is not provided, loads ALL active employees.
     */
    public function loadRelay(Request $request, $shiftPlanId)
    {
        try {
            $relayShift = $request->input('relay_shift');
            $limit = $request->input('limit', 15);

            $result = $this->service->loadRelayWorkforce($shiftPlanId, $relayShift, $limit);

            return response()->json([
                'status'     => $result['status'],
                'message'    => $result['message'],
                'data'       => $result['data'],
                'stats'      => isset($result['stats']) ? $result['stats'] : null,
                'pagination' => isset($result['pagination']) ? $result['pagination'] : null,
            ], $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * GET /shift-plans/{shift_plan_id}/workforce
     *
     * Paginated list of deployed workforce for the shift.
     */
    public function index(Request $request, $shiftPlanId)
    {
        try {
            $limit = $request->input('limit', 15);

            $result = $this->service->getWorkforceList($shiftPlanId, $limit);

            if ($result['status'] === 404) {
                return response()->json([
                    'status'  => 404,
                    'message' => $result['message'],
                    'data'    => [],
                ], 404);
            }

            return response()->json([
                'status'     => $result['status'],
                'message'    => $result['message'],
                'data'       => $result['data'],
                'stats'      => isset($result['stats']) ? $result['stats'] : null,
                'pagination' => isset($result['pagination']) ? $result['pagination'] : null,
            ], $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * GET /shift-plans/{shift_plan_id}/workforce/available-employees?search=
     *
     * Returns employees available for borrowing (not deployed on any shift that date).
     */
    public function availableEmployees(Request $request, $shiftPlanId)
    {
        try {
            $search = $request->query('search');
            $shiftId = $request->query('shift_id');

            $result = $this->service->getAvailableEmployeesForBorrowing($shiftPlanId, $search, $shiftId);

            return response()->json([
                'status'  => $result['status'],
                'message' => $result['message'],
                'data'    => $result['data'],
            ], $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * POST /shift-plans/{shift_plan_id}/workforce/borrow
     *
     * Borrow one or more employees from other relays into this shift.
     * Accepts { "employee_ids": [12, 15, 18], "borrowing_reason": "..." }
     * Enforces BR-SHFT-012 (one shift per date) and EF-01/EF-02 validations per employee.
     */
    public function borrow(BorrowEmployeeRequest $request, $shiftPlanId)
    {
        try {
            $limit = $request->input('limit', 15);
            $result = $this->service->borrowEmployees(
                $shiftPlanId,
                $request->employee_ids,
                $request->borrowing_reason,
                auth()->id(),
                $limit
            );

            return response()->json([
                'status'     => $result['status'],
                'message'    => $result['message'],
                'data'       => $result['data'],
                'errors'     => isset($result['errors']) ? $result['errors'] : null,
                'stats'      => isset($result['stats']) ? $result['stats'] : null,
                'pagination' => isset($result['pagination']) ? $result['pagination'] : null,
            ], $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * DELETE /shift-plans/{shift_plan_id}/workforce/{deployment_id}
     *
     * Soft-removes a deployment (status='removed') — does NOT hard delete.
     * Requires removed_reason in the request body.
     */
    public function destroy(RemoveDeploymentRequest $request, $shiftPlanId, $deploymentId)
    {
        try {
            $limit = $request->input('limit', 15);
            $result = $this->service->removeDeployment(
                $deploymentId,
                $request->removed_reason,
                $limit
            );

            return response()->json([
                'status'     => $result['status'],
                'message'    => $result['message'],
                'data'       => $result['data'],
                'stats'      => isset($result['stats']) ? $result['stats'] : null,
                'pagination' => isset($result['pagination']) ? $result['pagination'] : null,
            ], $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * GET /shift-plans/{shift_plan_id}/workforce/summary
     *
     * Returns workforce summary counts for the shift plan.
     */
    public function summary($shiftPlanId)
    {
        try {
            $result = $this->service->getWorkforceSummary($shiftPlanId);

            return response()->json([
                'status'  => $result['status'],
                'message' => $result['message'],
                'data'    => $result['data'],
            ], $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
                'data'    => [],
            ], 500);
        }
    }
}
