<?php

namespace App\Services;

use App\Exceptions\VecvApiException;
use App\Models\EquipmentFuelReading;
use App\Models\EquipmentName;
use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * Pulls live fuel telemetry from VECV and stores it as immutable readings.
 *
 * One clientId request returns the whole provisioned fleet, which keeps the
 * endpoint's 1-request-per-minute limit comfortably out of the way.
 */
class VecvFuelSyncService
{
    /** @var \App\Services\VecvClient */
    protected $client;

    public function __construct(VecvClient $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch and store the current fleet snapshot.
     *
     * @param  array  $chassisNumbers  Optional subset; defaults to the whole client fleet.
     * @return array  Summary counts for the caller to report.
     *
     * @throws \App\Exceptions\VecvApiException
     */
    public function sync(array $chassisNumbers = [])
    {
        $body = $this->client->post('fuel', $this->payload($chassisNumbers));

        $rows = Arr::get($body, 'fuelData');

        if (! is_array($rows)) {
            throw new VecvApiException('VECV fuel response contained no fuelData array.');
        }

        $machineMap = $this->machineMap();
        $staleAfter = (int) config('vecv.stale_after_minutes');
        $now        = Carbon::now();

        $prepared = [];
        $summary  = [
            'received'   => count($rows),
            'stored'     => 0,
            'duplicates' => 0,
            'stale'      => 0,
            'unmatched'  => 0,
            'skipped'    => 0,
        ];

        foreach ($rows as $row) {
            $chassis = trim((string) Arr::get($row, 'chassisNo', ''));

            // Without a chassis there is nothing to key the reading on.
            if ($chassis === '') {
                $summary['skipped']++;
                continue;
            }

            $reportedAt = $this->reportedAt($row);
            if ($reportedAt === null) {
                $summary['skipped']++;
                continue;
            }

            $key = strtoupper($chassis);
            $equipmentNameId = isset($machineMap[$key]) ? $machineMap[$key] : null;

            if ($equipmentNameId === null) {
                $summary['unmatched']++;
            }

            if ($reportedAt->diffInMinutes($now) > $staleAfter) {
                $summary['stale']++;
            }

            $prepared[] = [
                'chassis_number'         => $chassis,
                'equipment_name_id'      => $equipmentNameId,
                'reg_no'                 => $this->nullIfBlank(Arr::get($row, 'regNo')),
                'fuel_type'              => $this->nullIfBlank(Arr::get($row, 'fuelType')),
                'vehicle_status'         => $this->nullIfBlank(Arr::get($row, 'vehicleStatus')),
                'latitude'               => $this->toFloat(Arr::get($row, 'latitude')),
                'longitude'              => $this->toFloat(Arr::get($row, 'longitude')),
                'vehicle_speed'          => $this->toFloat(Arr::get($row, 'vehicleSpeed')),
                'odometer'               => $this->toFloat(Arr::get($row, 'odometer')),
                'engine_operating_hours' => $this->toFloat(Arr::get($row, 'engineOperatingHours')),
                'fuel_level_pct'         => $this->toFloat(Arr::get($row, 'fuelLevelInPer')),
                'fuel_level_ltr'         => $this->toFloat(Arr::get($row, 'fuelLevelInLtr')),
                'def_level_ltr'          => $this->toFloat(Arr::get($row, 'defLevelInLtr')),
                // Vendor misspelling. Delivered as a string; do not "correct" the key.
                'lifetime_fuel_consumed' => $this->toFloat(Arr::get($row, 'lifeTimeFuelConsumtion')),
                'soc_level'              => $this->toFloat(Arr::get($row, 'socLevel')),
                'battery_temperature'    => $this->toFloat(Arr::get($row, 'batteryTemperature')),
                'co2_saving'             => $this->toFloat(Arr::get($row, 'co2Saving')),
                'reported_at'            => $reportedAt->toDateTimeString(),
                'raw'                    => json_encode($row),
                'created_at'             => $now->toDateTimeString(),
                'updated_at'             => $now->toDateTimeString(),
            ];
        }

        if (! empty($prepared)) {
            $before = EquipmentFuelReading::count();

            // The unique (chassis_number, reported_at) constraint absorbs
            // vehicles that have not reported since the last poll.
            EquipmentFuelReading::insertOrIgnore($prepared);

            $summary['stored']     = EquipmentFuelReading::count() - $before;
            $summary['duplicates'] = count($prepared) - $summary['stored'];
        }

        return $summary;
    }

    /**
     * Build the request body.
     *
     * clientId returns every vehicle provisioned against the key in a single
     * call, so it is preferred over enumerating chassis numbers.
     *
     * @param  array  $chassisNumbers
     * @return array
     *
     * @throws \App\Exceptions\VecvApiException
     */
    protected function payload(array $chassisNumbers)
    {
        if (! empty($chassisNumbers)) {
            $trimmed = array_values(array_filter(array_map('trim', $chassisNumbers), function ($value) {
                return $value !== '';
            }));

            if (! empty($trimmed)) {
                return ['chassisNo' => $trimmed];
            }
        }

        $clientId = config('vecv.client_id');

        if (empty($clientId)) {
            throw new VecvApiException(
                'VECV_CLIENT_ID is not configured and no chassis numbers were supplied. '
                . 'The fuel endpoint requires at least one of clientId, regNo or chassisNo.'
            );
        }

        return ['clientId' => $clientId];
    }

    /**
     * Map chassis number to machine id.
     *
     * This project stores the chassis number in equipment_names.equipment_name.
     *
     * @return array
     */
    protected function machineMap()
    {
        $map = [];

        EquipmentName::query()
            ->select('id', 'equipment_name')
            ->get()
            ->each(function ($machine) use (&$map) {
                $name = strtoupper(trim((string) $machine->equipment_name));
                if ($name !== '') {
                    $map[$name] = $machine->id;
                }
            });

        return $map;
    }

    /**
     * Resolve the instant a reading was taken.
     *
     * epochTime is true UTC and is preferred. lastUpdated carries the same
     * instant rendered in IST, so it is only a fallback.
     *
     * @param  array  $row
     * @return \Carbon\Carbon|null
     */
    protected function reportedAt(array $row)
    {
        $epoch = Arr::get($row, 'epochTime');

        if (! empty($epoch) && is_numeric($epoch)) {
            return Carbon::createFromTimestampUTC((int) $epoch);
        }

        $lastUpdated = Arr::get($row, 'lastUpdated');

        if (! empty($lastUpdated) && preg_match('/^\d{14}$/', (string) $lastUpdated)) {
            try {
                return Carbon::createFromFormat('YmdHis', (string) $lastUpdated, 'Asia/Kolkata')->setTimezone('UTC');
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Cast a value that may arrive as a string, or be absent entirely.
     *
     * @param  mixed  $value
     * @return float|null
     */
    protected function toFloat($value)
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param  mixed  $value
     * @return string|null
     */
    protected function nullIfBlank($value)
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
