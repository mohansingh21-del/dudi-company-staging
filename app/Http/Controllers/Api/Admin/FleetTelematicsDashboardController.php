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
     *   from, to           Y-m-d, both inclusive. Omitted means live. A window
     *                      shows each machine's last reading inside it, with
     *                      staleness measured against the end of the window
     *                      rather than against now. Safety events are summed
     *                      across the whole window.
     *   date               Y-m-d shorthand for a one-day window.
     *   range              today | yesterday | last_7_days | last_30_days |
     *                      this_month | last_month. A named shorthand the
     *                      picker's preset buttons can send instead of
     *                      computing from/to themselves. Ignored when from/to
     *                      (or date) are also sent - explicit dates win.
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
            // The date picker sends a range. Today, Yesterday, Last 7 Days and
            // Custom Range all reduce to from/to; "date" stays accepted as
            // shorthand for a single day.
            'from'               => $request->input('from'),
            'to'                 => $request->input('to'),
            'date'               => $request->input('date'),
            'range'              => $request->input('range'),
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
     * Pull fresh telemetry from every feed. Write only - it returns sync
     * progress, never dashboard content, so the GETs stay the single way to
     * read the dashboard and a first page load and a post-refresh reload cannot
     * disagree.
     *
     * All the feeds cannot be fetched in one request: VECV throttles by API key
     * rather than by endpoint, so a successful call is followed by a 429 on
     * whatever comes next, and a full pass therefore spans minutes. It runs one
     * of two ways depending on what the host allows, and the response says
     * which in "mode":
     *
     *   background - a detached artisan pass walks every feed on its own. Poll
     *                GET refresh-status until running is false.
     *   stepwise   - shared hosting with exec disabled. This call fetched every
     *                feed that needed no wait; sleep next_in_seconds and POST
     *                again until complete.
     *
     * Either way a rate-limited feed stays pending and is retried rather than
     * skipped: a skipped feed is a silent gap that nothing downstream reveals.
     *
     * @return JsonResponse
     */
    public function refresh(FleetRefreshRunner $runner): JsonResponse
    {
        $userId = optional(request()->user())->id;

        try {
            // Preferred: hand the whole pass to a detached process so the admin
            // presses once and walks away.
            if ($runner->canRunInBackground()) {
                $started = $runner->startBackgroundPass();

                Log::channel('vecv')->info(
                    $started ? 'Refresh started (background)' : 'Refresh already running',
                    ['user_id' => $userId]
                );

                return response()->json([
                    'status'  => true,
                    'mode'    => 'background',
                    'message' => $started
                        ? 'Refreshing all telematics feeds. This takes a few minutes.'
                        : 'A refresh is already running.',
                    'data'    => $runner->status(),
                ], 202);
            }

            // Fallback for hosts with exec disabled: driven by the caller, one
            // round trip per rate-limit window. Every feed that can go right
            // now goes now - Truck Connect is on its own budget, so the first
            // press also gets the VECV fuel call in, which is what the
            // dashboard renders from.
            $state = $runner->runReady();

            Log::channel('vecv')->info('Refresh step (stepwise)', [
                'user_id' => $userId,
                'ran'     => $state['ran'],
            ]);

            return response()->json([
                'status'  => true,
                'mode'    => 'stepwise',
                'message' => $this->stepMessage($state),
                'data'    => $state,
            ], 200);
        } catch (\Throwable $th) {
            Log::channel('vecv')->error('Refresh failed', [
                'user_id' => $userId,
                'message' => $th->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Could not start the telematics refresh.',
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * What the last stepwise call did, for the UI to show as-is.
     *
     * @param  array  $state
     * @return string
     */
    protected function stepMessage(array $state)
    {
        if ($state['complete']) {
            return empty($state['failed'])
                ? 'All telematics feeds refreshed successfully.'
                : 'Refresh finished. Could not fetch: '
                    . implode(', ', array_map(function ($feed) {
                        return str_replace('_', ' ', $feed);
                    }, $state['failed'])) . '.';
        }

        if ($state['ran'] !== null) {
            $label     = str_replace('_', ' ', $state['ran']);
            $remaining = count($state['pending']) . ' feed(s) remaining.';

            // result is null when the attempt did not succeed. Saying
            // "Refreshed X" for a rate-limited attempt would report a fetch
            // that never happened - the feed is still pending and will be
            // retried, and the message has to say so.
            return $state['result'] === null
                ? 'Could not fetch ' . $label . ' yet - will retry. ' . $remaining
                : 'Refreshed ' . $label . '. ' . $remaining;
        }

        return 'Waiting ' . $state['next_in_seconds'] . 's for the next rate-limit window.';
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

        // Nothing is working on it and it is not finished: the caller has to
        // post again. Said plainly, because the feeds otherwise sit at
        // "pending, attempts 0" and read as a stall.
        if (! empty($state['awaiting_caller'])) {
            return $state['progress'] . ' done. POST refresh again'
                . ($state['next_in_seconds'] > 0
                    ? ' in ' . $state['next_in_seconds'] . 's'
                    : ' now')
                . ' to continue - ' . count($state['pending']) . ' feed(s) left.';
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

        // Not finished, nobody waiting on the caller: the scheduler is carrying
        // it. Saying "stopped" here would send someone chasing a fault that is
        // not there.
        if (($state['mode'] ?? null) === 'scheduled') {
            return 'Refreshing telematics feeds - ' . $state['progress'] . ' done'
                . ($state['pending'] ? ', ' . str_replace('_', ' ', $state['pending'][0]) . ' next' : '')
                . '. This continues on its own.';
        }

        return 'Refresh stopped before finishing - ' . $state['progress'] . ' done.';
    }
}
