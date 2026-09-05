<?php

namespace App\Console\Commands;

use App\Services\FleetRefreshRunner;
use Illuminate\Console\Command;

/**
 * Advances a refresh cycle that is waiting for its next rate-limit window.
 *
 * Why this exists
 * ---------------
 * A full refresh spans minutes, because VECV throttles by API key and the feeds
 * have to be spaced a minute apart. Something has to carry the cycle forward
 * between those windows.
 *
 * On a host that allows exec, the refresh button launches a detached artisan
 * pass and this is never needed. Shared hosting usually disables exec, and
 * without a substitute the browser has to post once per feed - which is what
 * the caller was originally spared from doing.
 *
 * Cron is normally still available on those hosts, so scheduling this every
 * minute restores the original contract: press once, poll status, done. It also
 * records a heartbeat, which is how the API tells whether anything is coming to
 * advance a cycle or the caller has to post again - a claim it cannot otherwise
 * make honestly.
 *
 * Cheap when idle: with no cycle in progress it returns without touching the
 * network.
 */
class AdvanceTelematicsRefreshCommand extends Command
{
    protected $signature = 'telematics:advance-refresh';

    protected $description = 'Carry an in-progress fleet refresh to its next feed (run every minute)';

    /**
     * @return int
     */
    public function handle(FleetRefreshRunner $runner)
    {
        // Recorded even when there is nothing to do: its purpose is to prove
        // the scheduler is alive, not that work happened.
        $runner->recordSchedulerHeartbeat();

        // Only ever carries forward a cycle somebody started. Without this the
        // command reads an empty status - every feed pending, complete false -
        // as work waiting to be done, and kicks off a full refresh of its own
        // every few minutes with nobody having asked for one.
        if (! $runner->hasCycle()) {
            return 0;
        }

        $state = $runner->status();

        if ($state['complete']) {
            return 0;
        }

        // A detached pass is already walking the feeds - stay out of its way,
        // or the two would spend each other's rate-limit windows.
        if ($state['running']) {
            return 0;
        }

        if ($state['next_in_seconds'] > 0) {
            $this->line(sprintf(
                'Waiting %ds for the next window (%s done).',
                $state['next_in_seconds'],
                $state['progress']
            ));

            return 0;
        }

        $state = $runner->runReady();

        $this->info(sprintf(
            '%s done%s.',
            $state['progress'],
            $state['ran'] ? ' - ran ' . $state['ran'] : ''
        ));

        return 0;
    }
}
