<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDelayRequest;
use App\Http\Requests\UpdateDelayRequest;
use App\Http\Requests\DelayRegisterFilterRequest;
use App\Http\Resources\DelayResource;
use App\Services\DelayService;
use App\Exceptions\ReadOnlyFieldMutationException;
use App\Exceptions\DelayNotFoundException;
use Illuminate\Support\Facades\Auth;

class DelayController extends Controller
{
    /**
     * @var DelayService
     */
    protected $service;

    public function __construct(DelayService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/v1/delays
     */
    public function index(DelayRegisterFilterRequest $request)
    {
        try {
            $result = $this->service->listRegister($request->validated());

            return response()->json([
                'status' => 200,
                'message' => 'Delay register retrieved successfully.',
                'kpi_summary' => $result['kpi_summary'],
                'chart_data' => $result['chart_data'],
                'data' => DelayResource::collection($result['records']),
                'pagination' => [
                    'current_page' => $result['records']->currentPage(),
                    'last_page' => $result['records']->lastPage(),
                    'per_page' => $result['records']->perPage(),
                    'total' => $result['records']->total(),
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to retrieve delay register',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/v1/delays
     */
    public function store(StoreDelayRequest $request)
    {
        try {
            $delay = $this->service->createDelay($request->validated(), Auth::id() ?? 1);

            return response()->json([
                'status' => 201,
                'message' => 'Delay logged successfully.',
                'data' => new DelayResource($delay),
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to create delay log',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/delays/{id}
     */
    public function show($id)
    {
        try {
            $delay = $this->service->getDelay((int) $id);

            return response()->json([
                'status' => 200,
                'message' => 'Delay details retrieved successfully.',
                'data' => new DelayResource($delay),
            ], 200);
        } catch (DelayNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => $e->getMessage(),
                'data' => null,
            ], 404);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to retrieve delay details',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/v1/delays/{id}
     */
    public function update(UpdateDelayRequest $request, $id)
    {
        try {
            $delay = $this->service->updateDelay((int) $id, $request->validated(), Auth::id() ?? 1);

            return response()->json([
                'status' => 200,
                'message' => 'Delay entry updated successfully.',
                'data' => new DelayResource($delay),
            ], 200);
        } catch (DelayNotFoundException $e) {
            return response()->json([
                'status' => 404,
                'message' => $e->getMessage(),
                'data' => null,
            ], 404);
        } catch (ReadOnlyFieldMutationException $e) {
            return response()->json([
                'status' => 422,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update delay entry',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/v1/admin/delays/import
     */
    public function import(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xls,xlsx,csv|max:5120',
        ]);

        try {
            $import = new \App\Imports\DelayImport();
            \Maatwebsite\Excel\Facades\Excel::import($import, $request->file('file'));

            $errors = $import->getErrors();
            $successCount = $import->getSuccessCount();

            if (count($errors) > 0) {
                return response()->json([
                    'status' => 422,
                    'message' => "Import completed with " . count($errors) . " errors. {$successCount} records imported successfully.",
                    'errors' => $errors
                ], 422);
            }

            return response()->json([
                'status' => 200,
                'message' => "Successfully imported {$successCount} delay records."
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to import delay records.',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
