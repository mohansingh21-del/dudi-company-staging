<?php

namespace App\Console\Commands;

use App\Exceptions\VecvApiException;
use App\Services\VecvServiceHistorySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncVecvServiceHistoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vecv:sync-service-history
                            {--chassis= : Comma separated chassis numbers; defaults to every chassis seen in telemetry}
                            {--from= : Start date (Y-m-d); defaults to the configured lookback}
                            {--to= : End date (Y-m-d); defaults to today}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull dealer workshop job cards from the VECV Service History API';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(VecvServiceHistorySyncService $sync)
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
                Log::info('vecv:sync-service-history rate limited', ['status' => $e->getStatusCode()]);

                return 0;
            }

            $this->error($e->getMessage());
            Log::error('vecv:sync-service-history failed', [
                'status'  => $e->getStatusCode(),
                'message' => $e->getMessage(),
            ]);

            return 1;
        }

        $this->info(sprintf(
            'Requested %d chassis over %d batch(es) for %s..%s: received %d job card(s), created %d, updated %d, %d line item(s).',
            $summary['requested'],
            $summary['batches'],
            $summary['from'],
            $summary['to'],
            $summary['received'],
            $summary['created'],
            $summary['updated'],
            $summary['items']
        ));

        if ($summary['unmatched'] > 0) {
            $this->warn(sprintf(
                '%d job card(s) have no matching machine in equipment_names.',
                $summary['unmatched']
            ));
        }

        if ($summary['skipped'] > 0) {
            $this->warn(sprintf(
                '%d record(s) skipped for a missing job card number or chassis.',
                $summary['skipped']
            ));
        }

        Log::info('vecv:sync-service-history completed', $summary);

        return 0;
    }
}
