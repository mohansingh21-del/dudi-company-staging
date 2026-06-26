<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShiftPlanRequest;
use App\Http\Requests\UpdateShiftPlanRequest;
use App\Http\Resources\ShiftPlanResource;
use App\Services\ShiftPlanService;
use Illuminate\Http\Request;

class ShiftPlanController extends Controller
{
    /**
     * @var ShiftPlanService
     */
    protected $service;

    public function __construct(ShiftPlanService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /admin/shift-plans/overview
     * Returns stats and a paginated list of shift plans for the dashboard overview.
     */
    public function overview(Request $request)
    {
        try {
            $filters = $request->only([
                'start_date',
                'end_date',
                'period',
                'status',
                'site_id',
                'supervisor_id',
                'search',
                'limit'
            ]);

            $result = $this->service->getShiftOverview($filters);
            $paginated = $result['shift_plans'];

            return response()->json([
                'status' => 200,
                'message' => 'Shift overview retrieved successfully.',
                'data' => [
                    'stats' => $result['stats'],
                    'shift_plans' => ShiftPlanResource::collection($paginated->items()),
                ],
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'from' => $paginated->firstItem(),
                    'to' => $paginated->lastItem(),
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * GET /admin/shift-plans
     * Returns a paginated list of shift plans.
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only(['date', 'period', 'shift_id', 'site_id', 'status', 'search', 'limit', 'start_date', 'end_date', 'supervisor_id']);
            $result = $this->service->listShiftPlans($filters);

            $paginated = $result['data'];

            return response()->json([
                'status' => 200,
                'message' => $result['message'],
                'data' => ShiftPlanResource::collection($paginated->items()),
                'summary' => $result['summary'] ?? null,
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'from' => $paginated->firstItem(),
                    'to' => $paginated->lastItem(),
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    /**
     * POST /admin/shift-plans
     * Create a new shift plan.
     */
    public function store(StoreShiftPlanRequest $request)
    {
        try {
            $result = $this->service->saveShiftPlan($request->validated());
 
            if ($result['status'] === 422) {
                return response()->json([
                    'status' => 422,
                    'message' => $result['message'],
                    'data' => null,
                ], 422);
            }

            return response()->json([
                'status' => 201,
                'message' => $result['message'],
                'data' => new ShiftPlanResource($result['data']),
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    /**
     * GET /admin/shift-plans/{id}
     * Returns details of a specific shift plan.
     */
    public function show($id)
    {
        try {
            $result = $this->service->getShiftPlan($id);

            if ($result['status'] === 404) {
                return response()->json([
                    'status' => 404,
                    'message' => $result['message'],
                    'data' => [],
                ], 404);
            }

            return response()->json([
                'status' => 200,
                'message' => $result['message'],
                'data' => new ShiftPlanResource($result['data']),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    /**
     * PUT/PATCH /admin/shift-plans/{id}
     * Updates an existing shift plan.
     */
    public function update(UpdateShiftPlanRequest $request, $id)
    {
        try {
            $result = $this->service->saveShiftPlan($request->validated(), $id);
 
            if ($result['status'] === 404) {
                return response()->json([
                    'status' => 404,
                    'message' => $result['message'],
                    'data' => [],
                ], 404);
            }

            if ($result['status'] === 422) {
                return response()->json([
                    'status' => 422,
                    'message' => $result['message'],
                    'data' => null,
                ], 422);
            }
 
            return response()->json([
                'status' => 200,
                'message' => $result['message'],
                'data' => new ShiftPlanResource($result['data']),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    /**
     * DELETE /admin/shift-plans/{id}
     * Deletes an existing shift plan.
     */
    public function destroy($id)
    {
        try {
            $result = $this->service->deleteShiftPlan($id);

            if ($result['status'] === 404) {
                return response()->json([
                    'status' => 404,
                    'message' => $result['message'],
                    'data' => [],
                ], 404);
            }

            return response()->json([
                'status' => 200,
                'message' => $result['message'],
                'data' => [],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    /**
     * PATCH /admin/shift-plans/{id}/status
     * Update the status of a shift plan.
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|in:draft,published,in_progress,planned,active,closed',
            ]);

            $result = $this->service->updateStatus($id, $request->status);

            if ($result['status'] === 404) {
                return response()->json([
                    'status' => 404,
                    'message' => $result['message'],
                    'data' => [],
                ], 404);
            }

            return response()->json([
                'status' => 200,
                'message' => $result['message'],
                'data' => new ShiftPlanResource($result['data']),
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    /**
     * POST /admin/shift-plans/{id}/publish
     * Confirms and publishes the shift plan.
     */
    public function publish($id)
    {
        try {
            $result = $this->service->publish($id, auth()->id());

            if ($result['status'] === 200) {
                return response()->json([
                    'status' => 200,
                    'message' => $result['message'],
                    'data' => new ShiftPlanResource($result['data']),
                ], 200);
            }

            return response()->json($result, $result['status']);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'data' => [],
            ], 500);
        }
    }
}
