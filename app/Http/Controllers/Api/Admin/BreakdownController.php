<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBreakdownRequest;
use App\Http\Requests\UpdateBreakdownRequest;
use App\Http\Resources\BreakdownDetailResource;
use App\Http\Resources\BreakdownListResource;
use App\Services\BreakdownService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BreakdownController extends Controller
{
    /**
     * @var BreakdownService
     */
    protected $service;

    public function __construct(BreakdownService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/v1/maintenance/breakdowns
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->all();
            $result = $this->service->list($filters);

            $tickets = $result['tickets'];
            $dashboard = $result['dashboard'];

            return response()->json([
                'status'     => 200,
                'message'    => 'Breakdown tickets retrieved successfully',
                'dashboard'  => $dashboard,
                'data'       => BreakdownListResource::collection($tickets),
                'pagination' => [
                    'total'        => $tickets->total(),
                    'current_page' => $tickets->currentPage(),
                    'per_page'     => $tickets->perPage(),
                    'last_page'    => $tickets->lastPage(),
                    'from'         => $tickets->firstItem(),
                    'to'           => $tickets->lastItem(),
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to retrieve breakdown tickets',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/v1/admin/maintenance/breakdowns/import
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xls,xlsx,csv|max:5120',
        ]);

        try {
            $import = new \App\Imports\BreakdownImport();
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
                'message' => "Successfully imported {$successCount} breakdown records."
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to import breakdown tickets.',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/v1/maintenance/breakdowns
     */
    public function store(StoreBreakdownRequest $request)
    {
        try {
            $ticket = $this->service->create($request->validated());

            return response()->json([
                'status'  => 201,
                'message' => 'Breakdown ticket created successfully',
                'data'    => new BreakdownDetailResource($ticket),
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to create breakdown ticket',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/maintenance/breakdowns/{id}
     */
    public function show($id)
    {
        try {
            $ticket = $this->service->find((int) $id);

            return response()->json([
                'status'  => 200,
                'message' => 'Breakdown ticket fetched successfully',
                'data'    => new BreakdownDetailResource($ticket),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 404,
                'message' => 'Breakdown ticket not found.',
            ], 404);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to fetch breakdown ticket',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/v1/maintenance/breakdowns/{id}
     */
    public function update(UpdateBreakdownRequest $request, $id)
    {
        try {
            $ticket = $this->service->find((int) $id);
            $updatedTicket = $this->service->update($ticket, $request->validated());

            $responsePayload = [
                'id'               => $updatedTicket->id,
                'status'           => $updatedTicket->status,
                'severity'         => $updatedTicket->severity,
                'description'      => $updatedTicket->description,
                'downtime_end'     => $updatedTicket->downtime_end ? $updatedTicket->downtime_end->toDateTimeString() : null,
                'downtime_minutes' => $updatedTicket->downtime_minutes,
                'resolution_notes' => $updatedTicket->resolution_notes,
                'resolved_by'      => $updatedTicket->resolved_by ? (optional($updatedTicket->resolver->employee)->name ?? (optional($updatedTicket->resolver)->email ?? null)) : null,
                'resolved_at'      => $updatedTicket->resolved_at ? $updatedTicket->resolved_at->toDateTimeString() : null,
            ];

            return response()->json([
                'status'  => 200,
                'message' => 'Breakdown ticket updated successfully',
                'data'    => $responsePayload,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 404,
                'message' => 'Breakdown ticket not found.',
            ], 404);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => 'Failed to update breakdown ticket',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }
}
