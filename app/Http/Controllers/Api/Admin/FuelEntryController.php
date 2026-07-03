<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFuelEntryRequest;
use App\Http\Requests\UpdateFuelEntryRequest;
use App\Http\Requests\FuelRegisterFilterRequest;
use App\Http\Requests\FuelDashboardFilterRequest;
use App\Http\Requests\FuelPerformanceFilterRequest;
use App\Http\Requests\FuelAllocationFilterRequest;
use App\Http\Requests\FuelSummaryFilterRequest;
use App\Services\FuelService;
use App\Http\Resources\FuelRegisterResource;
use App\Exceptions\MachineNotInShiftException;
use App\Exceptions\ReadingRegressionException;
use App\Exceptions\ReadOnlyFieldMutationException;
use App\Exceptions\FuelRecordNotFoundException;
use Illuminate\Support\Facades\Auth;

class FuelEntryController extends Controller
{
    /**
     * @var FuelService
     */
    protected $service;

    public function __construct(FuelService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/v1/fuel-entries
     */
    public function index(FuelRegisterFilterRequest $request)
    {
        try {
            $result = $this->service->listRegister($request->all());
            $records = $result['records'];
            $summary = $result['summary'];

            $isEmpty = $records->isEmpty();

            return response()->json([
                'status'     => 200,
                'message'    => $isEmpty ? 'No Fuel Records Found' : 'Fuel entries retrieved successfully.',
                'summary'    => $summary,
                'efficiency_trends' => $result['efficiency_trends'] ?? [],
                'data'       => FuelRegisterResource::collection($records->getCollection()),
                'pagination' => [
                    'total'        => $records->total(),
                    'current_page' => $records->currentPage(),
                    'per_page'     => $records->perPage(),
                    'last_page'    => $records->lastPage(),
                    'from'         => $records->firstItem(),
                    'to'           => $records->lastItem(),
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to retrieve fuel entries',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/v1/fuel-entries
     */
    public function store(StoreFuelEntryRequest $request)
    {
        try {
            $entry = $this->service->createEntry($request->validated(), Auth::id() ?? 1);

            return response()->json([
                'status'  => 201,
                'message' => 'Fuel entry created successfully.',
                'data'    => $entry,
            ], 201);
        } catch (MachineNotInShiftException $e) {
            return response()->json([
                'status'  => 422,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 422);
        } catch (ReadingRegressionException $e) {
            return response()->json([
                'status'  => 422,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to create fuel entry',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/fuel-entries/{id}
     */
    public function show($id)
    {
        try {
            $entry = $this->service->getEntry((int) $id);

            return response()->json([
                'status'  => 200,
                'message' => 'Fuel entry retrieved successfully.',
                'data'    => $entry,
            ], 200);
        } catch (FuelRecordNotFoundException $e) {
            return response()->json([
                'status'  => 404,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 404);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to retrieve fuel entry',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/v1/fuel-entries/{id}
     */
    public function update(UpdateFuelEntryRequest $request, $id)
    {
        try {
            $entry = $this->service->updateEntry((int) $id, $request->validated(), Auth::id() ?? 1);

            return response()->json([
                'status'  => 200,
                'message' => 'Fuel entry updated successfully.',
                'data'    => $entry,
            ], 200);
        } catch (FuelRecordNotFoundException $e) {
            return response()->json([
                'status'  => 404,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 404);
        } catch (MachineNotInShiftException $e) {
            return response()->json([
                'status'  => 422,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 422);
        } catch (ReadingRegressionException $e) {
            return response()->json([
                'status'  => 422,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 422);
        } catch (ReadOnlyFieldMutationException $e) {
            return response()->json([
                'status'  => 422,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to update fuel entry',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/fuel-entries/dashboard
     */
    public function dashboard(FuelDashboardFilterRequest $request)
    {
        try {
            $result = $this->service->getDashboard($request->all());
            if ($result === null) {
                return response()->json([
                    'status'  => 200,
                    'message' => 'No Fuel Activity Available',
                    'data'    => null,
                ], 200);
            }

            $hasWorkDone = $result['has_work_done'];
            unset($result['has_work_done']);

            return response()->json([
                'status'  => 200,
                'message' => $hasWorkDone ? 'Dashboard data retrieved successfully' : 'Pending Production Data',
                'data'    => $result,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to retrieve dashboard data',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/fuel-entries/performance
     */
    public function performance(FuelPerformanceFilterRequest $request)
    {
        try {
            $result = $this->service->getPerformance($request->all());
            if ($result === null) {
                return response()->json([
                    'status'  => 200,
                    'message' => 'No Fuel Activity Available',
                    'data'    => null,
                ], 200);
            }

            $hasWorkDone = $result['has_work_done'];
            unset($result['has_work_done']);

            return response()->json([
                'status'  => 200,
                'message' => $hasWorkDone ? 'Performance analytics retrieved successfully' : 'Pending Production Data',
                'data'    => $result,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to retrieve performance analytics',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/fuel-entries/allocation-tracking
     */
    public function allocationTracking(FuelAllocationFilterRequest $request)
    {
        try {
            $result = $this->service->getAllocationTracking($request->all());
            $records = $result['records'];

            return response()->json([
                'status'     => 200,
                'message'    => $records->isEmpty() ? 'No Fuel Records Found' : 'Allocation tracking retrieved successfully',
                'data'       => $records->items(),
                'pagination' => [
                    'total'        => $records->total(),
                    'current_page' => $records->currentPage(),
                    'per_page'     => $records->perPage(),
                    'last_page'    => $records->lastPage(),
                    'from'         => $records->firstItem(),
                    'to'           => $records->lastItem(),
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to retrieve allocation tracking',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/fuel-entries/summary
     */
    public function summary(FuelSummaryFilterRequest $request)
    {
        try {
            $result = $this->service->getSummary($request->all());

            return response()->json([
                'status'  => 200,
                'message' => empty($result['machines']) ? 'No Fuel Summary Available' : 'Machine rollup summary retrieved successfully',
                'data'    => $result,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to retrieve summary',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }
}
