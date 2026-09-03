<?php

namespace App\Console\Commands;

use App\Services\FleetRefreshRunner;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fetch every VECV feed in one pass, spaced to respect the rate limit.
 *
 * VECV throttles by API key rather than by endpoint - a successful fuel call is
 * followed immediately by a 429 on location, and on service history too - so
 * the feeds cannot be fetched together. Each needs its own window, and a full
 * pass therefore takes minutes.
 *
 * That is why this is a command and not something the refresh endpoint does
 * inline: the HTTP request starts this in the background and returns at once.
 *
 * Service history looked for a while as though it carried a longer cooldown of
 * its own, failing several attempts in a row. It does not: a lookback wide
 * enough to split into two date windows made two back-to-back requests, and the
 * second was refused before the first had cleared. The lookback now spans
 * exactly one window, and multi-request runs space themselves.
 *
 * Being rate limited is never treated as "skip this feed": the step stays
 * pending and is retried until it succeeds or runs out of attempts, because a
 * skipped feed is a silent gap that nothing downstream would reveal.
 */
class SyncVecvAllCommand extends Command
{
    protected $signature = 'vecv:sync-all
                            {--interval= : Seconds between feeds; defaults to the configured cooldown}';

    protected $description = 'Fetch every VECV feed in sequence, spaced to respect the one-request-per-minute limit';

    /**
     * @return int
     */
    public function handle(FleetRefreshRunner $runner)
    {
        $started = Carbon::now();

        $this->info('Running all VECV feeds. This takes a few minutes.');
        $this->newLine();

        // The runner owns the sequencing, the retry rule and the progress
        // state, so a pass started from here and one started from the refresh
        // button are the same thing and report through the same status
        // endpoint.
        $state = $runner->runAll($this->option('interval'));

        $rows = [];

        foreach ($state['feeds'] as $feed => $detail) {
            $rows[] = [
                $feed,
                strtoupper($detail['status']),
                $detail['attempts'],
                $this->describe($detail['detail']),
            ];
        }

        $this->table(['Feed', 'Status', 'Attempts', 'Detail'], $rows);
        $this->line('Elapsed: ' . $started->diffInSeconds(Carbon::now()) . 's');

        // Non-zero only when a feed actually failed, so a shell caller can tell
        // a clean pass from a partial one.
        return empty($state['failed']) ? 0 : 1;
    }

    /**
     * One-line rendering of whichever detail shape a feed produced.
     *
     * @param  mixed  $detail
     * @return string
     */
    protected function describe($detail)
    {
        if (! is_array($detail)) {
            return '-';
        }

        if (isset($detail['error'])) {
            return $detail['error'];
        }

        $parts = [];

        foreach (['received', 'stored', 'duplicates', 'created', 'updated', 'items', 'unmatched', 'stale'] as $key) {
            if (array_key_exists($key, $detail)) {
                $parts[] = $key . ' ' . $detail[$key];
            }
        }

        return empty($parts) ? 'done' : implode(', ', $parts);
    }
}
