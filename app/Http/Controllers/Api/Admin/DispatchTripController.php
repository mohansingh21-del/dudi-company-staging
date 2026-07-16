<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDispatchTripRequest;
use App\Http\Requests\UpdateDispatchTripRequest;
use App\Services\DispatchTripService;
use App\Exceptions\CompletedShiftOverrideException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DispatchTripController extends Controller
{
    /**
     * @var DispatchTripService
     */
    protected $service;

    /**
     * DispatchTripController constructor.
     *
     * @param DispatchTripService $service
     */
    public function __construct(DispatchTripService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/v1/dispatch/trips
     * Retrieve a paginated register of dispatch trips.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 15);
            $result = $this->service->getRegister($request->all(), $perPage);

            return response()->json([
                'status' => 200,
                'message' => 'Trips fetched successfully',
                'dashboard' => $result['summary'],
                'data' => $result['records']->items(),
                'pagination' => [
                    'total' => $result['records']->total(),
                    'current_page' => $result['records']->currentPage(),
                    'per_page' => $result['records']->perPage(),
                    'last_page' => $result['records']->lastPage(),
                    'from' => $result['records']->firstItem(),
                    'to' => $result['records']->lastItem(),
                ]
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Internal server error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/v1/dispatch/trips
     * Log a new trip.
     *
     * @param StoreDispatchTripRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreDispatchTripRequest $request)
    {
        try {
            $userId = Auth::id() ?? 1;
            $trip = $this->service->logTrip($request->validated(), $userId);

            // Check if duplicate for warning flag
            $isDuplicate = \App\Models\DispatchTrip::where('dumper_equipment_id', $trip->dumper_equipment_id)
                ->where('start_time', $trip->start_time)
                ->where('end_time', $trip->end_time)
                ->where('id', '!=', $trip->id)
                ->exists();

            $responsePayload = [
                'status' => 201,
                'message' => 'Trip Logged Successfully.',
                'data' => $trip,
            ];

            if ($isDuplicate) {
                // Soft warning flag in meta
                $responsePayload['meta'] = [
                    'warning' => 'Duplicate Trip Warning: Another trip exists with the same dumper and time intervals.'
                ];
            }

            return response()->json($responsePayload, 201);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Internal server error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/v1/dispatch/trips/{id}
     * Retrieve trip details.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $data = $this->service->getTripDetails($id);

            return response()->json([
                'status' => 200,
                'message' => 'Trip details fetched successfully',
                'data' => $data
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Trip Record Not Found'
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Internal server error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/v1/dispatch/trips/{id}
     * Update an existing trip.
     *
     * @param UpdateDispatchTripRequest $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateDispatchTripRequest $request, $id)
    {
        try {
            $userId = Auth::id() ?? 1;
            $trip = $this->service->updateTrip($id, $request->validated(), $userId);

            return response()->json([
                'status' => 200,
                'message' => 'Trip Updated Successfully.',
                'data' => $trip
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => 'Trip Record Not Found'
            ], 404);
        } catch (CompletedShiftOverrideException $e) {
            return response()->json([
                'status' => 403,
                'message' => $e->getMessage()
            ], 403);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Internal server error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/v1/dispatch/dashboard
     * Retrieve dispatch dashboard KPIs and trend data.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function dashboard(Request $request)
    {
        try {
            $data = $this->service->getDashboard($request->all());

            return response()->json([
                'status' => 200,
                'message' => 'Dashboard fetched successfully',
                'data' => $data
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Internal server error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/v1/dispatch/dumper-summary
     * Retrieve group rollup of dumpers.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function dumperSummary(Request $request)
    {
        try {
            $data = $this->service->getDumperSummary($request->all());

            return response()->json([
                'status' => 200,
                'message' => 'Dumper summary fetched successfully',
                'data' => $data
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Internal server error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/v1/dispatch/fleet-performance
     * Retrieve detailed fleet analytics.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function fleetPerformance(Request $request)
    {
        try {
            $data = $this->service->getFleetPerformance($request->all());

            return response()->json([
                'status' => 200,
                'message' => 'Fleet performance fetched successfully',
                'data' => $data
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Internal server error.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/v1/dispatch/trips/import
     * Bulk upload dispatch and dumping operations.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        try {
            $import = new \App\Imports\DispatchImport();
            \Maatwebsite\Excel\Facades\Excel::import($import, $request->file('file'));

            if (count($import->getErrors()) > 0) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Import completed with errors.',
                    'errors' => $import->getErrors(),
                ], 422);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Trips imported successfully.',
                'success_count' => $import->getSuccessCount(),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to import trips.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
