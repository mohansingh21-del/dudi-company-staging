<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Concerns\GuardsMasterDeactivation;
use App\Http\Requests\StoreSitePointRequest;
use App\Http\Requests\UpdateSitePointRequest;
use App\Http\Resources\SitePointResource;
use App\Models\SitePoint;
use App\Services\SitePointService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class SitePointController extends Controller
{
    use GuardsMasterDeactivation;

    /**
     * @var SitePointService
     */
    protected $service;

    public function __construct(SitePointService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/v1/site-points
     * Public listing — active site points only.
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only(['site_id', 'type']);
            $points  = $this->service->getPublicList($filters);

            return response()->json([
                'status'  => 200,
                'message' => 'Site points fetched successfully.',
                'data'    => SitePointResource::collection($points),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 500,
                'message' => 'Internal server error.',
                'data'    => [],
            ], 500);
        }
    }

    /**
     * GET /api/v1/admin/site-points
     * Admin listing — all records, paginated.
     */
    public function adminIndex(Request $request)
    {
        try {
            $filters   = $request->only(['site_id', 'type', 'is_active', 'search', 'page', 'per_page']);
            $paginated = $this->service->getAdminList($filters);

            return response()->json([
                'status'     => 200,
                'message'    => 'Site points fetched successfully.',
                'data'       => SitePointResource::collection($paginated->items()),
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page'    => $paginated->lastPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                    'from'         => $paginated->firstItem(),
                    'to'           => $paginated->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 500,
                'message' => 'Internal server error.',
                'data'    => [],
            ], 500);
        }
    }

    /**
     * GET /api/v1/admin/site-points/{id}
     * Show a single site point.
     */
    public function show($id)
    {
        try {
            $point = $this->service->getById($id);

            return response()->json([
                'status'  => 200,
                'message' => 'Site point fetched successfully.',
                'data'    => new SitePointResource($point),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 404,
                'message' => 'Record not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 500,
                'message' => 'Internal server error.',
                'data'    => [],
            ], 500);
        }
    }

    /**
     * POST /api/v1/admin/site-points
     * Create a new site point.
     */
    public function store(StoreSitePointRequest $request)
    {
        try {
            $point = $this->service->create(
                $request->validated(),
                auth()->id()
            );

            return response()->json([
                'status'  => 201,
                'message' => 'Site point created successfully.',
                'data'    => new SitePointResource($point),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 500,
                'message' => 'Internal server error.',
                'data'    => [],
            ], 500);
        }
    }

    /**
     * PUT /api/v1/admin/site-points/{id}
     * Update an existing site point.
     */
    public function update(UpdateSitePointRequest $request, $id)
    {
        try {
            $point = $this->service->update($id, $request->validated());

            return response()->json([
                'status'  => 200,
                'message' => 'Site point updated successfully.',
                'data'    => new SitePointResource($point),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 404,
                'message' => 'Record not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 500,
                'message' => 'Internal server error.',
                'data'    => [],
            ], 500);
        }
    }

    /**
     * PATCH /api/v1/admin/site-points/{id}/status
     * Toggle the is_active status.
     */
    public function toggleStatus($id)
    {
        try {
            // The service flips the flag, so the guard has to see the row
            // before it does — the new status is the flip of the current one.
            $point = SitePoint::findOrFail($id);

            if ($blocked = $this->blockDeactivation($point, !$point->is_active)) {
                return $blocked;
            }

            $point = $this->service->toggleStatus($id);

            return response()->json([
                'status'  => 200,
                'message' => 'Site point status updated successfully.',
                'data'    => new SitePointResource($point),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => 404,
                'message' => 'Record not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 500,
                'message' => 'Internal server error.',
                'data'    => [],
            ], 500);
        }
    }
}
