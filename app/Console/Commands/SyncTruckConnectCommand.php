<?php

namespace App\Console\Commands;

use App\Exceptions\TruckConnectApiException;
use App\Services\TruckConnectSyncService;
use App\Models\TruckConnectReading;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pull the Truckonnect signal snapshot for every subscribed truck.
 *
 * One GET returns the whole fleet, and the vendor documents no rate limit -
 * this endpoint is meant to be polled every minute - so unlike the VECV feeds
 * there is nothing to stagger or retry around.
 */
class SyncTruckConnectCommand extends Command
{
    protected $signature = 'truckconnect:sync';

    protected $description = 'Pull raw signal data for subscribed Truck Connect vehicles and store it as readings';

    /**
     * @return int
     */
    public function handle(TruckConnectSyncService $sync)
    {
        try {
            $summary = $sync->sync();
        } catch (TruckConnectApiException $e) {
            $this->error($e->getMessage());

            Log::channel('vecv')->error('truckconnect:sync failed', [
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

        if ($summary['unmatched'] > 0) {
            $this->warn(sprintf(
                '%d VIN(s) are not registered in equipment_names - run telematics:register-chassis.',
                $summary['unmatched']
            ));
        }

        if ($summary['unlocated'] > 0) {
            $this->warn(sprintf('%d reading(s) carried no GPS fix.', $summary['unlocated']));
        }

        if ($summary['skipped'] > 0) {
            $this->warn(sprintf('%d row(s) skipped for a missing VIN or GpsTime.', $summary['skipped']));
        }

        // The vendor semantics that are still open. Surfaced on every run
        // because the readings look complete either way - the raw values are
        // stored, they simply cannot be interpreted yet.
        $pending = TruckConnectReading::pendingConfirmations();

        if (! empty($pending)) {
            $this->newLine();
            $this->warn('Stored raw, still awaiting confirmation from the vendor: ' . implode(', ', $pending) . '.');
            $this->line('Set the matching knobs in config/truckconnect.php once answered - already');
            $this->line('stored rows become correct at once, with no backfill.');
        }

        Log::channel('vecv')->info('truckconnect:sync completed', $summary);

        return 0;
    }
}
