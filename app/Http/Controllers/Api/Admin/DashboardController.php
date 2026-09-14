<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardSummaryRequest;
use App\Http\Requests\TopConsumersRequest;
use App\Http\Requests\LowEfficiencyRequest;
use App\Http\Requests\RecentTransactionsRequest;
use App\Http\Requests\TopCategoriesRequest;
use App\Http\Requests\CriticalDelaysRequest;
use App\Http\Requests\RecentDelaysRequest;
use App\Http\Requests\RecentTripsRequest;
use App\Services\DashboardService;
use App\Services\FuelDashboardService;
use App\Services\DelayDashboardService;
use App\Services\DispatchDashboardService;
use App\Services\InventoryDashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * @var DashboardService
     */
    protected $dashboardService;

    /**
     * @var FuelDashboardService
     */
    protected $fuelService;

    /**
     * @var DelayDashboardService
     */
    protected $delayService;

    /**
     * @var DispatchDashboardService
     */
    protected $dispatchService;

    /**
     * @var InventoryDashboardService
     */
    protected $inventoryService;

    /**
     * Inject services.
     */
    public function __construct(
        DashboardService $dashboardService,
        FuelDashboardService $fuelService,
        DelayDashboardService $delayService,
        DispatchDashboardService $dispatchService,
        InventoryDashboardService $inventoryService
    ) {
        $this->dashboardService = $dashboardService;
        $this->fuelService = $fuelService;
        $this->delayService = $delayService;
        $this->dispatchService = $dispatchService;
        $this->inventoryService = $inventoryService;
    }

    /**
     * GET /api/v1/dashboard/summary
     *
     * @param DashboardSummaryRequest $request
     * @return JsonResponse
     */
    public function summary(DashboardSummaryRequest $request): JsonResponse
    {
        try {
            $filters = $request->getResolvedFilters();
            $data = $this->dashboardService->getSummary($filters);

            return response()->json([
                'status'  => true,
                'message' => 'Dashboard summary retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve dashboard summary.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/fuel/top-consumers
     *
     * @param TopConsumersRequest $request
     * @return JsonResponse
     */
    public function topConsumers(TopConsumersRequest $request): JsonResponse
    {
        try {
            $filters = $request->getResolvedFilters();
            $perPage = (int) $request->input('per_page', 10);
            $data = $this->fuelService->getTopConsumers($filters, $perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Top fuel consumers retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve top fuel consumers.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/fuel/low-efficiency
     *
     * @param LowEfficiencyRequest $request
     * @return JsonResponse
     */
    public function lowEfficiency(LowEfficiencyRequest $request): JsonResponse
    {
        try {
            $filters = $request->getResolvedFilters();
            $perPage = (int) $request->input('per_page', 10);
            $data = $this->fuelService->getLowEfficiency($filters, $perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Low efficiency alerts retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve low efficiency alerts.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/fuel/recent-transactions
     *
     * @param RecentTransactionsRequest $request
     * @return JsonResponse
     */
    public function recentTransactions(RecentTransactionsRequest $request): JsonResponse
    {
        try {
            $filters = $request->getResolvedFilters();
            $perPage = (int) $request->input('per_page', 10);
            $data = $this->fuelService->getRecentTransactions($filters, $perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Recent fuel transactions retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve recent fuel transactions.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/delay/top-categories
     *
     * @param TopCategoriesRequest $request
     * @return JsonResponse
     */
    public function topCategories(TopCategoriesRequest $request): JsonResponse
    {
        try {
            $filters = $request->getResolvedFilters();
            $perPage = (int) $request->input('per_page', 10);
            $data = $this->delayService->getTopCategories($filters, $perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Top delay categories retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve top delay categories.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/delay/critical-delays
     *
     * @param CriticalDelaysRequest $request
     * @return JsonResponse
     */
    public function criticalDelays(CriticalDelaysRequest $request): JsonResponse
    {
        try {
            $filters = $request->getResolvedFilters();
            $perPage = (int) $request->input('per_page', 10);
            $data = $this->delayService->getCriticalDelays($filters, $perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Critical delay events retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve critical delay events.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/delay/recent-delays
     *
     * @param RecentDelaysRequest $request
     * @return JsonResponse
     */
    public function recentDelays(RecentDelaysRequest $request): JsonResponse
    {
        try {
            $filters = $request->getResolvedFilters();
            $perPage = (int) $request->input('per_page', 10);
            $data = $this->delayService->getRecentDelays($filters, $perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Recent delay logs retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve recent delay logs.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/dispatch/recent-trips
     *
     * @param RecentTripsRequest $request
     * @return JsonResponse
     */
    public function recentTrips(RecentTripsRequest $request): JsonResponse
    {
        try {
            $filters = $request->getResolvedFilters();
            $perPage = (int) $request->input('per_page', 10);
            $data = $this->dispatchService->getRecentTrips($filters, $perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Recent dispatch trips retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve recent dispatch trips.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/inventory/below-min-level
     *
     * @param DashboardSummaryRequest $request
     * @return JsonResponse
     */
    public function belowMinLevelProducts(DashboardSummaryRequest $request): JsonResponse
    {
        try {
            $filters = $request->getResolvedFilters();
            $perPage = (int) $request->input('per_page', 10);
            $data = $this->inventoryService->getBelowMinLevel($filters['store_id'], $perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Below min level products retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve below min level products.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/inventory/out-of-stock
     *
     * @param DashboardSummaryRequest $request
     * @return JsonResponse
     */
    public function outOfStockProducts(DashboardSummaryRequest $request): JsonResponse
    {
        try {
            $filters = $request->getResolvedFilters();
            $perPage = (int) $request->input('per_page', 10);
            $data = $this->inventoryService->getOutOfStock($filters['store_id'], $perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Out of stock products retrieved successfully.',
                'data'    => $data,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve out of stock products.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }
}
