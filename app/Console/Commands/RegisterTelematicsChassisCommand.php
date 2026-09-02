<?php

namespace App\Console\Commands;

use App\Exceptions\VecvApiException;
use App\Models\Equipment;
use App\Models\EquipmentFuelReading;
use App\Models\EquipmentLocationReading;
use App\Models\EquipmentName;
use App\Models\TruckConnectReading;
use App\Services\VecvFuelSyncService;
use App\Services\VecvLocationSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Registers every chassis number seen on a telematics feed as an equipment_names
 * row under the "Dumper" equipment type.
 *
 * Why this exists
 * ---------------
 * The VECV services key their readings on a chassis number and try to resolve it
 * to a machine through equipment_names.equipment_name (see
 * VecvSyncService::machineMap()). Nothing populates equipment_names with chassis
 * numbers, so every reading lands unmatched and the fleet dashboard can only
 * ever show a raw chassis. This command closes that gap: whatever chassis the
 * feeds report, it makes sure there is a matching equipment_names row so the
 * next sync resolves it.
 *
 * The client's fleet on these feeds is entirely dumpers, so every discovered
 * chassis is filed under equipment_id = the "Dumper" row. If a non-dumper ever
 * appears it can be re-pointed by hand afterwards; this command never moves a
 * row it did not create.
 *
 * Sources
 * -------
 *   - equipment_fuel_readings.chassis_number      (VECV fuel feed)
 *   - equipment_location_readings.chassis_number  (VECV location feed)
 *   - truck_connect_readings.vin                  (Truck Connect feed)
 *
 * It reads those tables rather than calling the APIs itself, so it works no
 * matter how the readings got there - the disabled schedulers, the dashboard
 * refresh button, or a manual sync. Pass --sync to pull a fresh VECV snapshot
 * first; the Truck Connect feed has no sync of its own yet, so its VINs are only
 * as current as the last dashboard refresh.
 *
 * Idempotent: matching is case-insensitive (the same normalisation machineMap()
 * uses), so re-running it never creates a duplicate.
 */
class RegisterTelematicsChassisCommand extends Command
{
    protected $signature = 'telematics:register-chassis
                            {--sync : Pull a fresh VECV fuel + location snapshot before harvesting}
                            {--dry-run : List what would be created without writing}';

    protected $description = 'Register telematics chassis numbers as Dumper machines in equipment_names';

    public function handle(VecvFuelSyncService $fuelSync, VecvLocationSyncService $locationSync)
    {
        $dumper = Equipment::where('name', 'Dumper')->first();

        if (! $dumper) {
            $this->error('No "Dumper" row in equipments. Run: php artisan db:seed --class=EquipmentSeeder');

            return 1;
        }

        if ($this->option('sync')) {
            $this->pullVecvSnapshot($fuelSync, $locationSync);
        }

        $discovered = $this->discoverChassis();

        if ($discovered->isEmpty()) {
            $this->warn('No chassis numbers found on any feed. Nothing to register.');

            return 0;
        }

        // Case-insensitive set of chassis already registered anywhere in
        // equipment_names - not just under Dumper. A chassis a human has already
        // filed under some other equipment type must not be duplicated here.
        $existing = EquipmentName::query()
            ->pluck('equipment_name')
            ->map(function ($name) {
                return strtoupper(trim((string) $name));
            })
            ->filter()
            ->flip();

        $toCreate = $discovered->reject(function ($chassis) use ($existing) {
            return $existing->has(strtoupper($chassis));
        })->values();

        if ($toCreate->isEmpty()) {
            $this->info(sprintf(
                '%d chassis on the feeds, all already registered. Nothing to do.',
                $discovered->count()
            ));

            return 0;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d chassis would be registered under Dumper (id %d):', $toCreate->count(), $dumper->id));
            $toCreate->each(fn ($chassis) => $this->line('  ' . $chassis));

            return 0;
        }

        $now = Carbon::now();

        $rows = $toCreate->map(function ($chassis) use ($dumper, $now) {
            return [
                'equipment_id'   => $dumper->id,
                'equipment_name' => $chassis,
                'is_active'      => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        })->all();

        EquipmentName::insert($rows);

        $this->info(sprintf(
            'Registered %d new chassis under Dumper (id %d). %d already existed.',
            $toCreate->count(),
            $dumper->id,
            $discovered->count() - $toCreate->count()
        ));

        Log::info('telematics:register-chassis completed', [
            'discovered' => $discovered->count(),
            'created'    => $toCreate->count(),
            'created_chassis' => $toCreate->all(),
        ]);

        return 0;
    }

    /**
     * Best-effort refresh of the VECV feeds. A rate limit or an upstream outage
     * is logged and swallowed - the harvest below still runs against whatever is
     * already stored.
     */
    protected function pullVecvSnapshot(VecvFuelSyncService $fuelSync, VecvLocationSyncService $locationSync): void
    {
        foreach (['fuel' => $fuelSync, 'location' => $locationSync] as $label => $service) {
            try {
                $summary = $service->sync();
                $this->line(sprintf('VECV %s sync: received %d, stored %d.', $label, $summary['received'], $summary['stored']));
            } catch (VecvApiException $e) {
                $this->warn(sprintf('VECV %s sync skipped: %s', $label, $e->getMessage()));
                Log::warning("telematics:register-chassis {$label} sync failed", ['message' => $e->getMessage()]);
            }
        }
    }

    /**
     * Distinct, trimmed chassis identifiers across every feed, de-duplicated
     * case-insensitively. Order is stable (sorted) so the log reads the same way
     * twice.
     *
     * @return \Illuminate\Support\Collection<int,string>
     */
    protected function discoverChassis()
    {
        $chassis = collect()
            ->merge(EquipmentFuelReading::query()->distinct()->pluck('chassis_number'))
            ->merge(EquipmentLocationReading::query()->distinct()->pluck('chassis_number'))
            ->merge(TruckConnectReading::query()->distinct()->pluck('vin'));

        return $chassis
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => strtoupper($value))
            ->sort()
            ->values();
    }
}
