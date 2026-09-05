<?php

namespace App\Services;

use App\Exceptions\VecvApiException;
use App\Models\EquipmentFuelReading;
use App\Services\TruckConnectSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Walks every VECV feed on demand, one call per press of the refresh button.
 *
 * VECV throttles by API key rather than by endpoint - a successful fuel call is
 * followed immediately by a 429 on location - so the four feeds cannot be
 * fetched together. Four feeds need four rate-limit windows, which is minutes
 * of wall clock, and no HTTP request should be held open for that.
 *
 * So this is a small state machine instead. One call runs one feed and reports
 * what remains; the caller polls until nothing is pending. Progress lives in the
 * cache, which is enough because a cycle is short-lived and losing one only
 * costs a repeat fetch, never data.
 *
 * A rate-limited feed stays pending and is retried on a later call. It is never
 * skipped: a skipped feed is a hole in the data that nothing downstream would
 * reveal.
 */
class FleetRefreshRunner
{
    const CACHE_KEY = 'vecv.refresh.cycle';

    /**
     * Set while a background pass is in flight.
     *
     * Carries a TTL so a run killed mid-flight - a deploy, a fatal - releases
     * the flag on its own instead of blocking every later click forever.
     */
    const RUNNING_KEY = 'vecv.refresh.running';

    /**
     * When the refresh button was last pressed.
     *
     * Recorded on the press itself, not on a console run, so it answers
     * exactly "when did someone last refresh this dashboard".
     */
    const LAST_PRESSED_KEY = 'vecv.refresh.last_pressed_at';

    /**
     * Last time the scheduled advancer ran.
     *
     * Its only job is to prove cron is alive, so the API can say honestly
     * whether a waiting cycle will move on its own or the caller has to post
     * again. Without it there is no way to tell a host with a working
     * scheduler from one without.
     */
    const SCHEDULER_HEARTBEAT_KEY = 'vecv.refresh.scheduler_heartbeat';

    /**
     * Run the next feed that still needs fetching.
     *
     * @return array
     */
    public function run()
    {
        $cycle = $this->cycle();

        $next = $this->nextFeed($cycle);

        // Nothing left - report the finished cycle rather than starting another
        // by surprise. The next call after this one begins a fresh cycle.
        if ($next === null) {
            $cycle['complete'] = true;
            $this->store($cycle);

            return $this->present($cycle, null, null);
        }

        // Too soon since the last outbound call. Answered here rather than by
        // spending the slot to be refused.
        $wait = $this->secondsUntilReady($cycle, $next);

        if ($wait > 0) {
            return $this->present($cycle, null, null, $wait);
        }

        // Only a throttled feed consumes the shared window. Stamping the clock
        // for Truck Connect would make the next VECV feed wait out a minute
        // that was never spent on VECV.
        if ($this->isThrottled($next)) {
            $cycle['last_call_at'] = Carbon::now()->toDateTimeString();
        }

        $cycle['feeds'][$next]['attempts']++;

        Log::channel('vecv')->info('Refresh cycle running feed', [
            'feed'    => $next,
            'attempt' => $cycle['feeds'][$next]['attempts'],
            'cycle'   => $cycle['started_at'],
        ]);

        try {
            $summary = $this->fetch($next);
        } catch (VecvApiException $e) {
            $cycle = $this->recordFailure($cycle, $next, $e->getMessage(), $e->isRateLimited());
            $this->store($cycle);

            return $this->present($cycle, $next, null);
        } catch (\Throwable $th) {
            $cycle = $this->recordFailure($cycle, $next, $th->getMessage(), false);
            $this->store($cycle);

            return $this->present($cycle, $next, null);
        }

        $cycle['feeds'][$next]['status'] = 'ok';
        $cycle['feeds'][$next]['detail'] = $summary;
        $cycle['feeds'][$next]['at']     = Carbon::now()->toDateTimeString();

        $this->store($cycle);

        Log::channel('vecv')->info('Refresh cycle feed completed', [
            'feed'    => $next,
            'summary' => $summary,
        ]);

        return $this->present($cycle, $next, $summary);
    }

    /**
     * Run every feed that can go right now, and stop at the first that must
     * wait.
     *
     * One press should get as far as it can. Truck Connect is on its own
     * budget, so the moment it finishes the VECV fuel call is already allowed -
     * returning after a single feed would make the caller post again for a
     * window that was never spent, and leave the dashboard empty until it did.
     * Fuel is what the dashboard renders from, so draining the free feeds gets
     * the screen correct on the first press.
     *
     * Bounded by the rate limit itself: as soon as a feed reports a wait, this
     * returns. There is no risk of holding the request open, because a feed
     * that can run takes well under a second.
     *
     * @return array
     */
    public function runReady()
    {
        // One iteration per feed at most. A feed that runs is removed from
        // pending, and one that must wait ends the loop, so this cap is only a
        // guard against a state machine bug rather than a real limit.
        $maxSteps = count((array) config('vecv.refresh_feeds'));

        $state = null;

        for ($step = 0; $step < $maxSteps; $step++) {
            $state = $this->run();

            // Finished the cycle, or the next feed needs a rate-limit window.
            if ($state['complete'] || $state['next_in_seconds'] > 0) {
                return $state;
            }

            // Nothing ran and nothing to wait for - should not happen, but
            // returning beats spinning.
            if ($state['ran'] === null) {
                return $state;
            }
        }

        return $state;
    }

    /**
     * Fetch every feed in one pass, waiting out the rate limit between each.
     *
     * This is what the refresh button starts, in a detached process: the whole
     * pass takes minutes, so it cannot happen inside the HTTP request, but the
     * admin should still get everything from a single click rather than having
     * to keep pressing.
     *
     * State is the same cycle the single-step run() writes, so refreshStatus()
     * reports live progress either way.
     *
     * @param  int|null  $interval  Seconds between feeds; defaults to the cooldown
     * @return array
     */
    public function runAll($interval = null)
    {
        $interval = $interval ?: (int) config('vecv.refresh_cooldown_seconds');

        $this->markRunning();

        // Start clean. A click means "fetch everything now", not "resume
        // whatever was half-done an hour ago".
        Cache::forget(self::CACHE_KEY);

        Log::channel('vecv')->info('Refresh pass started', ['interval' => $interval]);

        try {
            // Bounded so a misbehaving feed can never spin forever. Every feed
            // gets at most refresh_max_attempts tries and each try is one
            // iteration, plus headroom for the waiting iterations between them.
            $maxIterations = (count((array) config('vecv.refresh_feeds')) + 1)
                * max(1, (int) config('vecv.refresh_max_attempts')) * 2;

            for ($i = 0; $i < $maxIterations; $i++) {
                $state = $this->run();

                if ($state['complete']) {
                    Log::channel('vecv')->info('Refresh pass finished', [
                        'done'   => $state['done'],
                        'failed' => $state['failed'],
                    ]);

                    return $state;
                }

                $wait = (int) $state['next_in_seconds'];

                // Zero means the next feed is on its own budget and can run at
                // once - go straight on. Written long-hand because `?: $interval`
                // treats a legitimate 0 as "no answer" and sleeps out a full
                // window that was never spent, which is exactly what made an
                // unthrottled feed still cost a minute.
                if ($wait > 0) {
                    sleep(min($wait, $interval));
                } elseif ($state['ran'] === null) {
                    // Nothing ran and nothing to wait for. Should not happen,
                    // but back off a second rather than spinning the loop.
                    sleep(1);
                }
            }

            Log::channel('vecv')->warning('Refresh pass hit its iteration cap', $this->status());
        } finally {
            $this->markStopped();
        }

        return $this->status();
    }

    /**
     * Whether a refresh cycle actually exists.
     *
     * status() reports an empty cycle - every feed pending - when nothing has
     * been started, which is right for display but indistinguishable from a
     * cycle in progress. Anything that decides whether to DO work has to check
     * this instead, or it will treat "nobody has asked for a refresh" as "a
     * refresh is waiting to be carried forward" and start one on its own.
     *
     * @return bool
     */
    public function hasCycle()
    {
        return is_array(Cache::get(self::CACHE_KEY));
    }

    /**
     * Record that the scheduled advancer ran.
     *
     * @return void
     */
    public function recordSchedulerHeartbeat()
    {
        Cache::put(self::SCHEDULER_HEARTBEAT_KEY, Carbon::now()->toDateTimeString(), now()->addMinutes(15));
    }

    /**
     * Whether the scheduler has run recently enough to be trusted to carry a
     * cycle forward.
     *
     * The window is generous - the advancer is scheduled every minute, so
     * anything inside five means cron is running. Being wrong in the strict
     * direction is the safe way round: the caller is told to post, which always
     * works, rather than told to wait for something that is not coming.
     *
     * @return bool
     */
    public function schedulerIsRunning()
    {
        $beat = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);

        if (empty($beat)) {
            return false;
        }

        try {
            return Carbon::parse($beat)->greaterThan(Carbon::now()->subMinutes(5));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether this host will let us launch a detached process.
     *
     * Shared hosting routinely disables exec and escapeshellarg through
     * disable_functions, and calling one then is a fatal, not a false - so it
     * has to be checked rather than attempted. When they are unavailable the
     * refresh falls back to running one feed per request instead.
     *
     * @return bool
     */
    public function canRunInBackground()
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        foreach (['exec', 'escapeshellarg'] as $function) {
            if (! function_exists($function) || in_array($function, $disabled, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Start a full pass in a detached process and return immediately.
     *
     * A pass takes minutes; an HTTP request must not. So the button launches
     * artisan and hands back at once, and the caller watches refresh-status.
     *
     * The running flag is claimed HERE rather than inside the child, because
     * the child takes a moment to boot: a second click arriving in that gap
     * would otherwise start a second pass, and the two would spend each other's
     * rate-limit windows and each conclude the feeds were throttled.
     *
     * @return bool  False when a pass is already in flight, or when this host
     *               cannot launch one
     */
    public function startBackgroundPass()
    {
        if (! $this->canRunInBackground() || $this->isRunning()) {
            return false;
        }

        $this->markRunning();

        Cache::forever(self::LAST_PRESSED_KEY, Carbon::now()->toDateTimeString());

        // Clear the previous cycle now so the very first status poll shows an
        // empty pass in progress rather than the last one's finished results.
        Cache::forget(self::CACHE_KEY);

        // Fully detached: setsid leaves the caller's session so the child is
        // not killed when the request ends, and all three streams are closed.
        // Without redirecting stdin as well, the parent can block waiting on a
        // pipe that nobody ever writes to - which looks exactly like the button
        // hanging, even though the pass itself started fine.
        $command = sprintf(
            'setsid nohup %s %s vecv:sync-all < /dev/null > /dev/null 2>&1 &',
            escapeshellarg(config('vecv.php_binary')),
            escapeshellarg(base_path('artisan'))
        );

        Log::channel('vecv')->info('Launching background refresh pass', [
            'binary' => config('vecv.php_binary'),
        ]);

        try {
            exec($command);
        } catch (\Throwable $e) {
            // Release the claim - nothing is going to clear it otherwise, and
            // the button would stay dead until the TTL expired.
            $this->markStopped();

            Log::channel('vecv')->error('Could not launch background refresh pass', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return true;
    }

    /**
     * When the refresh button was last pressed, or null if never.
     *
     * @return string|null
     */
    public static function lastPressedAt()
    {
        return Cache::get(self::LAST_PRESSED_KEY);
    }

    /**
     * Whether a background pass is currently in flight.
     *
     * @return bool
     */
    public function isRunning()
    {
        return (bool) Cache::get(self::RUNNING_KEY);
    }

    /**
     * @return void
     */
    protected function markRunning()
    {
        // Comfortably longer than a full pass, short enough that a crashed run
        // does not lock the button out for the rest of the day.
        Cache::put(self::RUNNING_KEY, Carbon::now()->toDateTimeString(), now()->addMinutes(
            max(1, (int) config('vecv.refresh_cycle_ttl_minutes'))
        ));
    }

    /**
     * @return void
     */
    protected function markStopped()
    {
        Cache::forget(self::RUNNING_KEY);
    }

    /**
     * The current cycle without touching VECV.
     *
     * @return array
     */
    public function status()
    {
        $cycle = Cache::get(self::CACHE_KEY);

        if (! is_array($cycle)) {
            return $this->present($this->freshCycle(), null, null);
        }

        return $this->present($cycle, null, null, $this->secondsUntilReady($cycle, $this->nextFeed($cycle)));
    }

    /*
    |--------------------------------------------------------------------------
    | Cycle state
    |--------------------------------------------------------------------------
    */

    /**
     * Load the cycle in progress, or begin a new one.
     *
     * A finished cycle is replaced rather than resumed, so pressing the button
     * again after a full pass fetches everything afresh.
     *
     * @return array
     */
    protected function cycle()
    {
        $cycle = Cache::get(self::CACHE_KEY);

        if (! is_array($cycle) || ! empty($cycle['complete'])) {
            return $this->freshCycle();
        }

        return $cycle;
    }

    /**
     * @return array
     */
    protected function freshCycle()
    {
        $feeds = [];

        foreach ((array) config('vecv.refresh_feeds') as $feed) {
            $feeds[$feed] = [
                'status'   => 'pending',
                'attempts' => 0,
                'detail'   => null,
                'at'       => null,
            ];
        }

        return [
            'started_at'   => Carbon::now()->toDateTimeString(),
            'last_call_at' => null,
            'complete'     => false,
            'feeds'        => $feeds,
        ];
    }

    /**
     * @param  array  $cycle
     * @return void
     */
    protected function store(array $cycle)
    {
        Cache::put(self::CACHE_KEY, $cycle, now()->addMinutes(
            max(1, (int) config('vecv.refresh_cycle_ttl_minutes'))
        ));
    }

    /**
     * The next feed still worth attempting, in configured order.
     *
     * @param  array  $cycle
     * @return string|null
     */
    protected function nextFeed(array $cycle)
    {
        foreach ($cycle['feeds'] as $feed => $state) {
            if ($state['status'] === 'pending') {
                return $feed;
            }
        }

        return null;
    }

    /**
     * Seconds remaining before another outbound call is allowed.
     *
     * @param  array  $cycle
     * @return int
     */
    protected function secondsUntilReady(array $cycle, $feed = null)
    {
        // A feed on its own budget is never made to wait for someone else's.
        if ($feed !== null && ! $this->isThrottled($feed)) {
            return 0;
        }

        if (empty($cycle['last_call_at'])) {
            return 0;
        }

        $cooldown = max(0, (int) config('vecv.refresh_cooldown_seconds'));
        $ready    = Carbon::parse($cycle['last_call_at'])->addSeconds($cooldown);
        $now      = Carbon::now();

        return $ready->greaterThan($now) ? $now->diffInSeconds($ready) : 0;
    }

    /**
     * Whether a feed draws on the shared VECV rate-limit budget.
     *
     * @param  string  $feed
     * @return bool
     */
    protected function isThrottled($feed)
    {
        return ! in_array($feed, (array) config('vecv.refresh_unthrottled_feeds'), true);
    }

    /**
     * Mark an attempt that did not succeed.
     *
     * Rate limiting leaves the feed pending so a later call retries it. Only a
     * real error, or running out of attempts, marks it failed - the cap keeps a
     * feed that is genuinely unavailable from holding a cycle open forever.
     *
     * @param  array   $cycle
     * @param  string  $feed
     * @param  string  $reason
     * @param  bool    $rateLimited
     * @return array
     */
    protected function recordFailure(array $cycle, $feed, $reason, $rateLimited)
    {
        $maxAttempts = max(1, (int) config('vecv.refresh_max_attempts'));
        $attempts    = $cycle['feeds'][$feed]['attempts'];

        $exhausted = $attempts >= $maxAttempts;

        $cycle['feeds'][$feed]['status'] = ($rateLimited && ! $exhausted) ? 'pending' : 'failed';
        $cycle['feeds'][$feed]['detail'] = ['error' => $reason];
        $cycle['feeds'][$feed]['at']     = Carbon::now()->toDateTimeString();

        Log::channel('vecv')->warning('Refresh cycle feed did not complete', [
            'feed'         => $feed,
            'attempt'      => $attempts,
            'rate_limited' => $rateLimited,
            'status'       => $cycle['feeds'][$feed]['status'],
            'reason'       => $reason,
        ]);

        return $cycle;
    }

    /*
    |--------------------------------------------------------------------------
    | Feeds
    |--------------------------------------------------------------------------
    */

    /**
     * Fetch one feed.
     *
     * @param  string  $feed
     * @return array
     *
     * @throws \App\Exceptions\VecvApiException
     */
    protected function fetch($feed)
    {
        switch ($feed) {
            case 'fuel':
                return app(VecvFuelSyncService::class)->sync();

            case 'location':
                return app(VecvLocationSyncService::class)->sync();

            case 'service_history':
                return app(VecvServiceHistorySyncService::class)->sync();

            case 'alerts':
                return $this->fetchAlerts();

            case 'truck_connect':
                return app(TruckConnectSyncService::class)->sync();
        }

        throw new VecvApiException('Unknown refresh feed: ' . $feed);
    }

    /**
     * Call the alert log endpoint.
     *
     * Nothing is stored: this endpoint has never returned a successful
     * response, so its payload shape is unknown and no table or mapping exists
     * for it. The call is still made every cycle - the day VECV fixes the
     * service, the log records the shape, which is the signal to build the
     * ingest against something real rather than a guess.
     *
     * @return array
     *
     * @throws \App\Exceptions\VecvApiException
     */
    protected function fetchAlerts()
    {
        $chassis = EquipmentFuelReading::query()
            ->distinct()
            ->pluck('chassis_number')
            ->filter()
            ->values()
            ->all();

        if (empty($chassis)) {
            throw new VecvApiException('No chassis known yet - the fuel feed has to run first.');
        }

        $end   = Carbon::now();
        $start = $end->copy()->subDays(max(1, (int) config('vecv.service_history_lookback_days')));

        $body = app(VecvClient::class)->post('alerts', [
            'chassisNo' => $chassis,
            'startDate' => $start->toDateTimeString(),
            'endDate'   => $end->toDateTimeString(),
        ]);

        // The gateway reports service failures inside a 200 response, so the
        // body decides the outcome, not the HTTP status.
        if (array_key_exists('success', $body) && ! $body['success']) {
            throw new VecvApiException('alert endpoint: ' . (string) ($body['message'] ?? 'unknown error'));
        }

        $shape = [];

        foreach ($body as $key => $value) {
            if (is_array($value)) {
                $shape[$key] = count($value);
                if (! empty($value) && is_array(reset($value))) {
                    $shape[$key . '_row_keys'] = array_keys(reset($value));
                }
            }
        }

        Log::channel('vecv')->info('ALERT ENDPOINT RESPONDED - shape observed', $shape);

        return ['stored' => 0, 'note' => 'shape logged; ingest not built yet', 'shape' => $shape];
    }

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */

    /**
     * Shape the cycle for the caller.
     *
     * @param  array        $cycle
     * @param  string|null  $ran      Feed attempted on this call
     * @param  array|null   $summary  Its result, when it succeeded
     * @param  int          $waiting  Seconds the caller must wait
     * @return array
     */
    protected function present(array $cycle, $ran, $summary, $waiting = 0)
    {
        $pending = [];
        $done    = [];
        $failed  = [];

        foreach ($cycle['feeds'] as $feed => $state) {
            if ($state['status'] === 'ok') {
                $done[] = $feed;
            } elseif ($state['status'] === 'failed') {
                $failed[] = $feed;
            } else {
                $pending[] = $feed;
            }
        }

        $complete = empty($pending);

        return [
            // The feed this call actually fetched, or null when the call was
            // inside the cooldown or the cycle was already finished.
            'ran'     => $ran,
            'result'  => $summary,

            'done'    => $done,
            'pending' => $pending,
            'failed'  => $failed,

            'complete' => $complete,

            // How long before another call will do any work. The caller should
            // poll on this rather than guessing an interval.
            'next_in_seconds' => $complete
                ? 0
                : ($waiting > 0 ? $waiting : $this->secondsUntilReady($cycle, $this->nextFeed($cycle))),

            'progress' => count($done) + count($failed) . '/' . count($cycle['feeds']),

            // True while a background pass is still working through the feeds.
            'running' => $this->isRunning(),

            // How this host runs a refresh, and therefore what the caller has
            // to do next.
            //
            //   background - a detached process is walking the feeds. Poll
            //                refresh-status until running turns false.
            //   stepwise   - exec is disabled here, so NOTHING advances the
            //                cycle on its own. Every feed needs its own POST
            //                to refresh. Polling status alone leaves the
            //                remaining feeds pending forever, with attempts 0,
            //                which reads as a stall rather than as "your move".
            //   scheduled  - exec is disabled, but cron is running the advancer
            //                every minute, so a waiting cycle moves on its own.
            //                Poll refresh-status, same as background.
            'mode' => $this->canRunInBackground()
                ? 'background'
                : ($this->schedulerIsRunning() ? 'scheduled' : 'stepwise'),

            // True only when the cycle is unfinished and genuinely nothing is
            // coming to move it - no detached pass, no scheduler. Then the
            // caller must POST refresh again or it sits here indefinitely.
            'awaiting_caller' => $this->hasCycle()
                && ! $complete
                && ! $this->isRunning()
                && ! $this->canRunInBackground()
                && ! $this->schedulerIsRunning(),

            // False until someone presses refresh. Without it a caller cannot
            // tell "no refresh has been asked for" from "a refresh is under
            // way", because both report every feed pending.
            'started' => $this->hasCycle(),

            'cycle_started_at' => $cycle['started_at'],
            'feeds'            => $cycle['feeds'],
        ];
    }
}
