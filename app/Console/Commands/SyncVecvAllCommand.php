<?php

namespace App\Console\Commands;

use App\Exceptions\VecvApiException;
use App\Services\VecvClient;
use App\Services\VecvFuelSyncService;
use App\Services\VecvLocationSyncService;
use App\Services\VecvServiceHistorySyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Run every VECV feed in sequence, one per rate-limit window.
 *
 * VECV throttles by API key, not by endpoint: a successful fuel call is
 * immediately followed by a 429 on location, and on service history too. So
 * the feeds cannot be fetched together - they have to be spaced out.
 *
 * The window is NOT a uniform one minute for every endpoint. fuel and location
 * recover reliably after ~60s, but service history has been observed 429ing on
 * six consecutive attempts at 65s spacing while fuel succeeded in the middle of
 * them, so it evidently carries a longer cooldown of its own. The vendor
 * documents none of this. Raise --interval and --attempts when a run reports
 * service history rate limited rather than assuming the feed is broken.
 *
 * Being rate limited is never treated as "skip this feed". The step is retried
 * on the next window until it succeeds or runs out of attempts, because a
 * skipped feed is a silent gap in the data that nothing later would reveal.
 *
 * This is a console command rather than something the refresh endpoint does
 * inline: a run takes minutes, which no HTTP request should be held open for.
 */
class SyncVecvAllCommand extends Command
{
    protected $signature = 'vecv:sync-all
                            {--interval=60 : Seconds to wait between endpoints}
                            {--attempts=3 : Attempts per endpoint before giving up}';

    protected $description = 'Fetch every VECV feed in sequence, spaced to respect the one-request-per-minute limit';

    /**
     * @return int
     */
    public function handle()
    {
        $interval = max(1, (int) $this->option('interval'));
        $attempts = max(1, (int) $this->option('attempts'));

        $steps = [
            'fuel' => function () {
                return app(VecvFuelSyncService::class)->sync();
            },
            'location' => function () {
                return app(VecvLocationSyncService::class)->sync();
            },
            'service_history' => function () {
                return app(VecvServiceHistorySyncService::class)->sync();
            },
            // No sync service exists for alerts: the endpoint has never
            // returned a successful response, so its shape is unknown and
            // nothing has been written to map or store it. The call is made
            // anyway - the moment VECV fixes it, this run reports the shape
            // instead of the failure, which is the signal to build the ingest.
            'alerts' => function () {
                return $this->probeAlerts();
            },
        ];

        $results = [];
        $index   = 0;
        $started = Carbon::now();

        foreach ($steps as $name => $step) {
            // Space every step but the first: the previous one just consumed
            // this key's window.
            if ($index > 0) {
                $this->line(sprintf('  waiting %ds for the next rate-limit window...', $interval));
                sleep($interval);
            }

            $index++;

            $results[$name] = $this->runStep($name, $step, $attempts, $interval);
        }

        $this->newLine();
        $this->summarise($results, $started);

        Log::info('vecv:sync-all completed', $results);

        // Non-zero only when something actually failed, so a scheduler or a
        // shell caller can tell a clean run from a partial one.
        $failed = array_filter($results, function ($r) {
            return $r['status'] === 'failed';
        });

        return empty($failed) ? 0 : 1;
    }

    /**
     * Run one feed, retrying while it is rate limited.
     *
     * @param  string    $name
     * @param  callable  $step
     * @param  int       $attempts
     * @param  int       $interval
     * @return array
     */
    protected function runStep($name, callable $step, $attempts, $interval)
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->info(sprintf('[%s] attempt %d/%d', $name, $attempt, $attempts));

            try {
                $summary = $step();
            } catch (VecvApiException $e) {
                if ($e->isRateLimited()) {
                    // Not a failure - the window simply had not reopened. Wait
                    // and try the same feed again rather than moving on.
                    if ($attempt < $attempts) {
                        $this->warn(sprintf('  rate limited, retrying in %ds', $interval));
                        sleep($interval);
                        continue;
                    }

                    $this->error('  rate limited on the final attempt');

                    return ['status' => 'failed', 'reason' => 'rate limited', 'attempts' => $attempt];
                }

                $this->error('  ' . $e->getMessage());

                return [
                    'status'   => 'failed',
                    'reason'   => $e->getMessage(),
                    'http'     => $e->getStatusCode(),
                    'attempts' => $attempt,
                ];
            } catch (\Throwable $th) {
                $this->error('  ' . $th->getMessage());

                return ['status' => 'failed', 'reason' => $th->getMessage(), 'attempts' => $attempt];
            }

            $this->line('  ' . $this->describe($summary));

            return ['status' => 'ok', 'attempts' => $attempt, 'summary' => $summary];
        }

        return ['status' => 'failed', 'reason' => 'exhausted attempts', 'attempts' => $attempts];
    }

    /**
     * Call the alert endpoint and report what came back.
     *
     * Throws on a body that reports failure inside a 200, so runStep() records
     * it the same way as any other error.
     *
     * @return array
     *
     * @throws \App\Exceptions\VecvApiException
     */
    protected function probeAlerts()
    {
        $chassis = \App\Models\EquipmentFuelReading::query()
            ->distinct()
            ->pluck('chassis_number')
            ->filter()
            ->values()
            ->all();

        if (empty($chassis)) {
            throw new VecvApiException('No chassis known yet - run the fuel sync first.');
        }

        $end   = Carbon::now();
        $start = $end->copy()->subDays((int) config('vecv.service_history_lookback_days'));

        $body = app(VecvClient::class)->post('alerts', [
            'chassisNo' => $chassis,
            'startDate' => $start->toDateTimeString(),
            'endDate'   => $end->toDateTimeString(),
        ]);

        // The gateway reports service failures inside a 200 response.
        if (array_key_exists('success', $body) && ! $body['success']) {
            throw new VecvApiException('alert endpoint: ' . ($body['message'] ?? 'unknown error'));
        }

        // First success: report the shape so the ingest can be written. Nothing
        // is stored - there is no table for a payload nobody has seen.
        $this->newLine();
        $this->info('  ALERTS RESPONDED. Envelope keys: ' . implode(', ', array_keys($body)));

        foreach ($body as $key => $value) {
            if (is_array($value) && ! empty($value) && is_array(reset($value))) {
                $this->line('  ' . $key . ' => array(' . count($value) . '), row keys: '
                    . implode(', ', array_keys(reset($value))));
            }
        }

        $this->warn('  Not stored - no ingest exists yet. Run vecv:probe-alerts for the full row.');

        return ['envelope' => array_keys($body), 'stored' => 0];
    }

    /**
     * One-line rendering of whichever summary shape a sync returned.
     *
     * @param  mixed  $summary
     * @return string
     */
    protected function describe($summary)
    {
        if (! is_array($summary)) {
            return 'done';
        }

        $parts = [];

        foreach (['received', 'stored', 'duplicates', 'created', 'updated', 'items', 'unmatched', 'stale'] as $key) {
            if (array_key_exists($key, $summary)) {
                $parts[] = $key . ' ' . $summary[$key];
            }
        }

        return empty($parts) ? 'done' : implode(', ', $parts);
    }

    /**
     * @param  array  $results
     * @param  \Carbon\Carbon  $started
     * @return void
     */
    protected function summarise(array $results, Carbon $started)
    {
        $rows = [];

        foreach ($results as $name => $result) {
            $rows[] = [
                $name,
                $result['status'] === 'ok' ? 'OK' : 'FAILED',
                $result['attempts'],
                $result['status'] === 'ok'
                    ? $this->describe($result['summary'] ?? null)
                    : ($result['reason'] ?? '-'),
            ];
        }

        $this->table(['Feed', 'Status', 'Attempts', 'Detail'], $rows);
        $this->line('Elapsed: ' . $started->diffInSeconds(Carbon::now()) . 's');
    }
}
