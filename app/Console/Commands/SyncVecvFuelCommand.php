<?php

namespace App\Console\Commands;

use App\Exceptions\VecvApiException;
use App\Services\VecvFuelSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncVecvFuelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vecv:sync-fuel
                            {--chassis= : Comma separated chassis numbers; defaults to the whole client fleet}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull live fuel telemetry from the VECV rFMS API and store it as readings';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(VecvFuelSyncService $sync)
    {
        $chassis = [];

        if ($this->option('chassis')) {
            $chassis = array_map('trim', explode(',', $this->option('chassis')));
        }

        try {
            $summary = $sync->sync($chassis);
        } catch (VecvApiException $e) {
            // The 1 request/minute limit is expected to bite occasionally when
            // a manual run overlaps the scheduler. It is not a failure.
            if ($e->isRateLimited()) {
                $this->warn('Rate limited by VECV - skipping this cycle.');
                Log::info('vecv:sync-fuel rate limited', ['status' => $e->getStatusCode()]);

                return 0;
            }

            $this->error($e->getMessage());
            Log::error('vecv:sync-fuel failed', [
                'status'  => $e->getStatusCode(),
                'message' => $e->getMessage(),
            ]);

            return 1;
        }

        $this->info(sprintf(
            'Received %d, stored %d, duplicate %d.',
            $summary['received'],
            $summary['stored'],
            $summary['duplicates']
        ));

        if ($summary['stale'] > 0) {
            $this->warn(sprintf(
                '%d vehicle(s) reported data older than %d minutes - their status is not live.',
                $summary['stale'],
                config('vecv.stale_after_minutes')
            ));
        }

        if ($summary['unmatched'] > 0) {
            $this->warn(sprintf(
                '%d chassis number(s) have no matching machine in equipment_names.',
                $summary['unmatched']
            ));
        }

        if ($summary['skipped'] > 0) {
            $this->warn(sprintf('%d row(s) skipped for missing chassis or timestamp.', $summary['skipped']));
        }

        Log::info('vecv:sync-fuel completed', $summary);

        return 0;
    }
}
