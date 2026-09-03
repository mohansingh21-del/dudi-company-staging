<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\FleetTelematicsDashboardService;
use App\Services\FleetRefreshRunner;
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
     * The dashboard filter bar, read once and applied identically by every
     * endpoint so the panels on screen can never disagree about what is being
     * shown.
     *
     *   date               Y-m-d. Omitted means live. A past date shows the last
     *                      reading of that day, with staleness measured against
     *                      the end of it rather than against now.
     *   machine_id         The dumper dropdown - equipment_names.id.
     *   connectivity       online | offline
     *   operational_status moving | stationary | engine_off | offline | unknown
     *   fuel_status        normal | low | critical
     *   source             vecv | truck_connect
     *   search             chassis or dumper number
     *
     * @param  Request  $request
     * @return array
     */
    protected function filters(Request $request): array
    {
        return [
            'date'               => $request->input('date'),
            'machine_id'         => $request->input('machine_id'),
            'connectivity'       => $request->input('connectivity'),
            'operational_status' => $request->input('operational_status'),
            'fuel_status'        => $request->input('fuel_status'),
            'source'             => $request->input('source'),
            'search'             => $request->input('search'),
        ];
    }

    /**
     * GET /api/v1/dashboard/fleet/summary
     *
     * @return JsonResponse
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'status'  => true,
                'message' => 'Fleet summary retrieved successfully.',
                'data'    => $this->dashboard->summary($this->filters($request)),
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
     * One row per machine across both telematics vendors, newest state first.
     *
     * Filters: search (chassis or dumper number), status (online|offline).
     * Paging: limit, page. Omitting limit returns the whole fleet on one page.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function vehicles(Request $request): JsonResponse
    {
        try {
            $result = $this->dashboard->vehicles($this->filters($request) + [
                'limit' => $request->input('limit'),
                'page'  => $request->input('page'),
            ]);

            return response()->json([
                'status'     => true,
                'message'    => 'Fleet telemetry retrieved successfully.',
                'data'       => $result['data'],
                'pagination' => $result['pagination'],
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
     * GET /api/v1/dashboard/fleet/operations
     *
     * The operations row in one response: the Moving / Stationary / Engine off
     * / Data unavailable split, and today's safety events.
     *
     * One endpoint because those two panels sit side by side and are read
     * together - "4 machines idling and 5 harsh braking events" is one thought.
     * Fetched separately they would also be built from two snapshots taken
     * moments apart, and the counts could disagree about the same machine.
     *
     * @return JsonResponse
     */
    public function operations(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'status'  => true,
                'message' => 'Fleet operations retrieved successfully.',
                'data'    => $this->dashboard->operations($this->filters($request)),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve fleet operations.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/fleet/fuel
     *
     * The whole fuel panel in one response: the Normal/Low/Critical donut, the
     * fleet average, and the vehicles running lowest.
     *
     * One endpoint because those are one panel on screen. Fetched separately
     * they would be built from two snapshots taken moments apart, and a vehicle
     * could sit in the Critical slice of the donut while the list beside it
     * still called it Low.
     *
     * @return JsonResponse
     */
    public function fuel(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'status'  => true,
                'message' => 'Fuel status retrieved successfully.',
                'data'    => $this->dashboard->fuelStatus($this->filters($request)),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve fuel status.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/dashboard/fleet/lowest-fuel
     *
     * Just the lowest-fuel rows. Kept for a standalone widget elsewhere; the
     * dashboard's fuel panel should use the fuel endpoint above, which returns
     * this list and the donut from one snapshot.
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
                'data'    => $this->dashboard->lowestFuel(
                    (int) $request->input('limit', 0),
                    $this->filters($request)
                ),
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
     * Start a full refresh - every feed - and return immediately.
     *
     * All four feeds cannot be fetched in one request. VECV throttles by API
     * key rather than by endpoint, so a successful call is followed by a 429 on
     * whatever comes next; the feeds have to be spaced a minute apart and a
     * full pass takes minutes. No HTTP request should be held open for that.
     *
     * So one press launches a detached artisan pass that walks every feed,
     * waiting out the limit between each and retrying anything rate limited
     * rather than skipping it. This returns 202 straight away, and the caller
     * polls refresh-status until running is false.
     *
     * Write only: it never returns dashboard content, so the three GETs remain
     * the single way to read the dashboard and a first page load and a
     * post-refresh reload cannot disagree.
     *
     * @return JsonResponse
     */
    public function refresh(FleetRefreshRunner $runner): JsonResponse
    {
        try {
            $started = $runner->startBackgroundPass();
        } catch (\Throwable $th) {
            Log::channel('vecv')->error('Refresh could not be started', [
                'user_id' => optional(request()->user())->id,
                'message' => $th->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Could not start the telematics refresh.',
                'error'   => $th->getMessage(),
            ], 500);
        }

        Log::channel('vecv')->info($started ? 'Refresh started' : 'Refresh already running', [
            'user_id' => optional(request()->user())->id,
        ]);

        return response()->json([
            'status'  => true,
            'message' => $started
                ? 'Refreshing all telematics feeds. This takes a few minutes.'
                : 'A refresh is already running.',
            'data'    => $runner->status(),
        ], 202);
    }

    /**
     * GET /api/v1/dashboard/fleet/refresh-status
     *
     * Where the current cycle stands, without calling VECV. Lets a page that
     * was reloaded mid-cycle pick the progress bar back up.
     *
     * @return JsonResponse
     */
    public function refreshStatus(FleetRefreshRunner $runner): JsonResponse
    {
        try {
            return response()->json([
                'status'  => true,
                'message' => $this->refreshMessage($state = $runner->status()),
                'data'    => $state,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to retrieve refresh status.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * A sentence describing where the pass stands, for the UI to show as-is.
     *
     * @param  array  $state
     * @return string
     */
    protected function refreshMessage(array $state)
    {
        if (! empty($state['running'])) {
            return 'Refreshing telematics feeds - ' . $state['progress'] . ' done'
                . ($state['pending'] ? ', currently ' . str_replace('_', ' ', $state['pending'][0]) : '')
                . '.';
        }

        if ($state['complete']) {
            // A pass that never ran reports complete with nothing done, which
            // is not the same as a pass that finished - say so plainly rather
            // than claiming success for work that never happened.
            if (empty($state['done']) && empty($state['failed'])) {
                return 'No refresh has run yet.';
            }

            return empty($state['failed'])
                ? 'All telematics feeds refreshed successfully.'
                : 'Refresh finished. Could not fetch: '
                    . implode(', ', array_map(function ($feed) {
                        return str_replace('_', ' ', $feed);
                    }, $state['failed'])) . '.';
        }

        return 'Refresh stopped before finishing - ' . $state['progress'] . ' done.';
    }
}
