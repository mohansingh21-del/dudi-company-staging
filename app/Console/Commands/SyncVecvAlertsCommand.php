<?php

namespace App\Console\Commands;

use App\Exceptions\VecvApiException;
use App\Services\VecvAlertSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pull driving-behaviour, fuel and device alerts from the VECV alert log.
 *
 * The range is capped at 48 hours per request, so a wider --from/--to is split
 * into windows and each costs its own rate-limit slot - a week is roughly four
 * minutes of wall clock.
 */
class SyncVecvAlertsCommand extends Command
{
    protected $signature = 'vecv:sync-alerts
                            {--chassis= : Comma separated chassis numbers; defaults to every chassis seen in telemetry}
                            {--from= : Start date (Y-m-d); defaults to the configured lookback}
                            {--to= : End date (Y-m-d); defaults to today}';

    protected $description = 'Pull alerts from the VECV alert log and store them';

    /**
     * @return int
     */
    public function handle(VecvAlertSyncService $sync)
    {
        $chassis = [];

        if ($this->option('chassis')) {
            $chassis = array_map('trim', explode(',', $this->option('chassis')));
        }

        try {
            $summary = $sync->sync($chassis, $this->option('from'), $this->option('to'));
        } catch (VecvApiException $e) {
            if ($e->isRateLimited()) {
                $this->warn('Rate limited by VECV - skipping this cycle.');

                return 0;
            }

            $this->error($e->getMessage());
            Log::channel('vecv')->error('vecv:sync-alerts failed', [
                'status'  => $e->getStatusCode(),
                'message' => $e->getMessage(),
            ]);

            return 1;
        }

        $this->info(sprintf(
            '%s to %s: received %d, stored %d, duplicate %d (%d request(s)).',
            $summary['from'],
            $summary['to'],
            $summary['received'],
            $summary['stored'],
            $summary['duplicates'],
            $summary['batches']
        ));

        if ($summary['unmatched'] > 0) {
            $this->warn(sprintf(
                '%d alert(s) are for a chassis not registered in equipment_names - '
                . 'run telematics:register-chassis, then telematics:relink-readings.',
                $summary['unmatched']
            ));
        }

        if ($summary['skipped'] > 0) {
            $this->warn(sprintf('%d row(s) skipped for a missing chassis, sub-type or time.', $summary['skipped']));
        }

        Log::channel('vecv')->info('vecv:sync-alerts completed', $summary);

        return 0;
    }
}
