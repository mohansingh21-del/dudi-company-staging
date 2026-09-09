<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceRecord;
use App\Http\Requests\StoreServiceRecordRequest;
use App\Http\Requests\UpdateServiceRecordRequest;
use App\Http\Resources\ServiceRecordListResource;
use App\Http\Resources\ServiceRecordReportResource;
use App\Services\ServiceRecordService;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;

class ServiceRecordController extends Controller
{
    /**
     * @var ServiceRecordService
     */
    protected $service;

    public function __construct(ServiceRecordService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a listing of service records with pagination and filters.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 15);

            // Listing stays deliberately slim: only the columns a table row shows,
            // and only the relations those columns need. The nested detail lives on
            // GET /service-records/{id}.
            $query = ServiceRecord::select([
                'id',
                'ticket_number',
                'machine_id',
                'site_id',
                'is_breakdown_service',
                'breakdown_id',
                'service_type',
                'service_date',
                'downtime_minutes',
                'status',
                'total_amount',
                'performed_by',
                'created_by',
                'created_at',
                'deleted_at',
            ])->with([
                'machine:id,equipment_name',
                'site:id,site_name',
                'breakdown:id,ticket_number',
                'store:id,name',
                'creator:id,email',
            ]);

            if ($request->filled('machine_id')) {
                $query->where('machine_id', $request->input('machine_id'));
            }

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('service_type')) {
                $query->where('service_type', $request->input('service_type'));
            }

            if ($request->filled('store_id')) {
                $query->where('store_id', $request->input('store_id'));
            }

            if ($request->filled('date_from')) {
                $query->where('service_date', '>=', $request->input('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->where('service_date', '<=', $request->input('date_to'));
            }

            // Search across the columns the listing actually shows. Grouped so it
            // stays ANDed with the filters above instead of widening them.
            if ($request->filled('search')) {
                $search = $request->input('search');

                $query->where(function ($q) use ($search) {
                    $q->where('ticket_number', 'LIKE', "%{$search}%")
                        ->orWhere('job_card_number', 'LIKE', "%{$search}%")
                        ->orWhere('performed_by', 'LIKE', "%{$search}%")
                        ->orWhereHas('machine', function ($q2) use ($search) {
                            $q2->where('equipment_name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('site', function ($q2) use ($search) {
                            $q2->where('site_name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('breakdown', function ($q2) use ($search) {
                            $q2->where('ticket_number', 'LIKE', "%{$search}%");
                        });
                });
            }

            $records = $query->orderBy('service_date', 'desc')->paginate($limit);

            return response()->json([
                'status'      => 200,
                'message'     => 'Service records fetched successfully.',
                'data'        => ServiceRecordListResource::collection($records->items()),
                'kpi_summary' => $this->service->getDashboardKpis(),
                'pagination'  => [
                    'current_page' => $records->currentPage(),
                    'last_page'    => $records->lastPage(),
                    'per_page'     => $records->perPage(),
                    'total'        => $records->total(),
                ],
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created service record in storage.
     *
     * @param StoreServiceRecordRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreServiceRecordRequest $request)
    {
        try {
            $userId = auth()->id() ? auth()->id() : 1;
            $serviceRecord = $this->service->create($request->validated(), $userId);

            return response()->json([
                'status'  => 201,
                'message' => 'Service record created successfully.',
                'data'    => $serviceRecord,
            ], 201);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified service record.
     *
     * @param ServiceRecord $serviceRecord
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(ServiceRecord $serviceRecord)
    {
        try {
            // Status history and audit logs are deliberately absent: they have
            // their own endpoint at /service-records/{id}/audit-trail.
            $serviceRecord->load([
                'machine.equipment',
                'site',
                'breakdown:id,ticket_number,status',
                'checklistDetail',
                'spareParts.storeProduct.store',
                'store',
                'attachments',
                'creator:id,email',
                'updater:id,email',
            ]);

            return response()->json([
                'status'  => 200,
                'message' => 'Service record fetched successfully.',
                'data'    => new ServiceRecordReportResource($serviceRecord),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified service record in storage.
     *
     * @param UpdateServiceRecordRequest $request
     * @param ServiceRecord $serviceRecord
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateServiceRecordRequest $request, ServiceRecord $serviceRecord)
    {
        try {
            $userId = auth()->id() ? auth()->id() : 1;
            $updatedRecord = $this->service->update($serviceRecord, $request->validated(), $userId);

            return response()->json([
                'status'  => 200,
                'message' => 'Service record updated successfully.',
                'data'    => $updatedRecord,
            ], 200);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified service record from storage (soft delete).
     *
     * @param ServiceRecord $serviceRecord
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(ServiceRecord $serviceRecord)
    {
        try {
            $userId = auth()->id() ? auth()->id() : 1;
            $this->service->delete($serviceRecord, $userId);

            return response()->json([
                'status'  => 200,
                'message' => 'Service record deleted successfully.',
                'data'    => null,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Get machine service history: summary cards plus the timeline log.
     *
     * @param Request $request
     * @param int $machineId
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request, $machineId)
    {
        try {
            $filters = $request->only(['service_type', 'status', 'date_from', 'date_to', 'limit']);
            $history = $this->service->getMachineServiceHistory((int) $machineId, $filters);

            return response()->json([
                'status'  => 200,
                'message' => 'Machine service history fetched successfully.',
                'data'    => $history,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Get full audit trail for a service record.
     *
     * @param ServiceRecord $serviceRecord
     * @return \Illuminate\Http\JsonResponse
     */
    public function auditTrail(ServiceRecord $serviceRecord)
    {
        try {
            $trail = $this->service->getAuditTrail($serviceRecord->id);

            return response()->json([
                'status'  => 200,
                'message' => 'Service record audit trail fetched successfully.',
                'data'    => $trail,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }
}
