<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBreakdownRequest;
use App\Http\Requests\UpdateBreakdownRequest;
use App\Http\Resources\BreakdownDetailResource;
use App\Http\Resources\BreakdownListResource;
use App\Models\BreakdownTicket;
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
                'id'                  => $updatedTicket->id,
                'status'              => $updatedTicket->status,
                'breakdown_date_time' => $updatedTicket->breakdown_date_time ? $updatedTicket->breakdown_date_time->toDateTimeString() : null,
                'date'                => $updatedTicket->date ? $updatedTicket->date->toDateString() : null,
                'severity'            => $updatedTicket->severity,
                'description'         => $updatedTicket->description,
                'downtime_end'        => $updatedTicket->downtime_end ? $updatedTicket->downtime_end->toDateTimeString() : null,
                'downtime_minutes'    => $updatedTicket->downtime_minutes,
                'resolution_notes'    => $updatedTicket->resolution_notes,
                'resolved_by'         => $updatedTicket->resolved_by ? (optional($updatedTicket->resolver->employee)->name ?? (optional($updatedTicket->resolver)->email ?? null)) : null,
                'resolved_at'         => $updatedTicket->resolved_at ? $updatedTicket->resolved_at->toDateTimeString() : null,
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

    /**
     * Get breakdown tickets that can still be linked to a service record.
     *
     * Defaults to every ticket that is not closed, which mirrors the check
     * ServiceRecordService applies before accepting a breakdown_id. Pass
     * ?status=open to narrow the dropdown to freshly reported tickets only.
     */
    public function getOpenBreakdowns(Request $request)
    {
        try {
            $linkableStatuses = ['open', 'in_progress', 'on_hold'];

            $query = BreakdownTicket::with(['equipment', 'equipmentName', 'breakdownType', 'shift'])
                ->whereIn('status', $linkableStatuses)
                // A ticket already claimed by a live service record is not selectable.
                // Cancelled records release it again; soft deleted ones are excluded
                // by the ServiceRecord model's own scope.
                ->whereDoesntHave('serviceRecords', function ($q) {
                    $q->where('status', '!=', 'cancelled');
                });

            // Optional narrowing to a single linkable status
            if ($request->filled('status')) {
                $status = strtolower($request->status);

                if (!in_array($status, $linkableStatuses)) {
                    return response()->json([
                        'status'  => 422,
                        'message' => 'Validation failed',
                        'errors'  => [
                            'status' => ['Status must be one of: ' . implode(', ', $linkableStatuses) . '.']
                        ]
                    ], 422);
                }

                $query->where('status', $status);
            }

            // Optional Machine Filter
            if ($request->filled('equipment_name_id')) {
                $query->where('equipment_name_id', $request->equipment_name_id);
            }

            // Optional Search on ticket number / machine name
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('ticket_number', 'LIKE', "%{$search}%")
                        ->orWhereHas('equipmentName', function ($q2) use ($search) {
                            $q2->where('equipment_name', 'LIKE', "%{$search}%");
                        });
                });
            }

            $query->orderBy('breakdown_date_time', 'desc');

            $format = function ($ticket) {
                return [
                    'id'                  => $ticket->id,
                    'ticket_number'       => $ticket->ticket_number,
                    'equipment_name_id'   => $ticket->equipment_name_id,
                    'equipment_name'      => optional($ticket->equipmentName)->equipment_name,
                    'equipment_category'  => optional($ticket->equipment)->name,
                    'breakdown_type'      => optional($ticket->breakdownType)->breakdown_type,
                    'severity'            => $ticket->severity,
                    'status'              => $ticket->status,
                    'mine_site_id'        => $ticket->mine_site_id,
                    'shift_name'          => optional($ticket->shift)->shift_name,
                    'breakdown_date_time' => $ticket->breakdown_date_time
                        ? $ticket->breakdown_date_time->toDateTimeString()
                        : null,
                    'description'         => $ticket->description,
                ];
            };

            if ($request->filled('limit')) {
                $tickets = $query->paginate($request->limit);

                return response()->json([
                    'status' => 200,
                    'message' => 'Open breakdowns fetched successfully',
                    'data' => collect($tickets->items())->map($format)->values()->toArray(),
                    'pagination' => [
                        'current_page' => $tickets->currentPage(),
                        'last_page' => $tickets->lastPage(),
                        'per_page' => $tickets->perPage(),
                        'total' => $tickets->total(),
                        'from' => $tickets->firstItem(),
                        'to' => $tickets->lastItem(),
                    ]
                ]);
            }

            $tickets = $query->get();

            return response()->json([
                'status' => 200,
                'message' => 'Open breakdowns fetched successfully',
                'data' => $tickets->map($format)->values()->toArray()
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }
}
