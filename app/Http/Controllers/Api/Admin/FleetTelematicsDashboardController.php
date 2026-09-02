<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\VecvApiException;
use App\Http\Controllers\Controller;
use App\Services\FleetTelematicsDashboardService;
use App\Services\VecvFuelSyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Live fleet telematics dashboard, backed by the VECV rFMS feed.
 *
 * The read endpoints only ever touch the database - they never call VECV - so
 * the dashboard renders instantly and can be polled freely. Fetching from the
 * vendor is a separate, explicit action on refresh().
 */
class FleetTelematicsDashboardController extends Controller
{
    /**
     * @var \App\Services\FleetTelematicsDashboardService
     */
    protected $dashboard;

    public function __construct(FleetTelematicsDashboardService $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * GET /api/v1/dashboard/fleet/summary
     *
     * @return JsonResponse
     */
    public function summary(): JsonResponse
    {
        try {
            return response()->json([
                'status'  => true,
                'message' => 'Fleet summary retrieved successfully.',
                'data'    => $this->dashboard->summary(),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve fleet summary.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/fleet/vehicles
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function vehicles(Request $request): JsonResponse
    {
        try {
            $data = $this->dashboard->vehicles([
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Fleet telemetry retrieved successfully.',
                'data'    => $data,
                'count'   => count($data),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve fleet telemetry.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/fleet/lowest-fuel
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function lowestFuel(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'status'  => true,
                'message' => 'Lowest fuel vehicles retrieved successfully.',
                'data'    => $this->dashboard->lowestFuel((int) $request->input('limit', 0)),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve lowest fuel vehicles.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/v1/dashboard/fleet/refresh
     *
     * Pull a fresh snapshot from VECV and store it. Write only - this returns
     * what the sync did, never dashboard content.
     *
     * Keeping it write-only means the three GETs stay the single way to read
     * the dashboard, so a page load and a post-refresh reload run exactly the
     * same code and cannot disagree. The caller refreshes, then re-reads.
     *
     * VECV allows one request per minute per key and answers 409 - not 429 -
     * when that is exceeded. Because this is a user-pressed button rather than
     * a schedule, several people on the dashboard at once will hit that limit
     * routinely, so it is translated into a plain 429 with a retry hint rather
     * than surfacing as an error. Nothing is broken when that happens: the
     * stored readings are simply at most a minute older, and the GETs still
     * serve them.
     *
     * @return JsonResponse
     */
    public function refresh(VecvFuelSyncService $sync): JsonResponse
    {
        try {
            $result = $sync->sync();
        } catch (VecvApiException $e) {
            if ($e->isRateLimited()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Telematics data was refreshed moments ago. Please try again in a minute.',
                ], 429);
            }

            Log::error('Fleet dashboard refresh failed', [
                'status'  => $e->getStatusCode(),
                'message' => $e->getMessage(),
            ]);

            // The upstream feed failed, not this application - 502 so the
            // front end can tell "VECV is down" from "our API is broken".
            return response()->json([
                'status'  => false,
                'message' => 'Could not reach the telematics provider.',
                'error'   => $e->getMessage(),
            ], 502);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to refresh telematics data.',
                'error'   => $th->getMessage(),
            ], 500);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Telematics data refreshed successfully.',

            // What the sync did, for the UI's "last synced" line and for
            // support. stored 0 with duplicates > 0 is the normal, healthy
            // case: no vehicle had reported anything new since the last pull.
            'sync'      => $result,
            'synced_at' => Carbon::now()->toDateTimeString(),
        ], 200);
    }
}
