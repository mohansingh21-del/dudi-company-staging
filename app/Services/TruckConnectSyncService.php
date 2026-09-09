<?php

namespace App\Services;

use App\Exceptions\TruckConnectApiException;
use App\Models\TruckConnectReading;
use App\Models\EquipmentName;
use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * Pulls the Truckonnect signal snapshot and stores it as immutable readings.
 *
 * Ambiguous vendor values are stored verbatim in *_raw columns and interpreted
 * at read time against config/truckconnect.php. Normalising on the way in would
 * bake a guess into the data; this way an answer from the vendor corrects every
 * row already stored, with no backfill and no re-fetch.
 */
class TruckConnectSyncService
{
    /** @var \App\Services\TruckConnectClient */
    protected $client;

    public function __construct(TruckConnectClient $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch and store the current fleet snapshot.
     *
     * @return array  Summary counts for the caller to report.
     *
     * @throws \App\Exceptions\TruckConnectApiException
     */
    public function sync()
    {
        $body = $this->client->daas();

        $rows = Arr::get($body, config('truckconnect.rows_key'));

        if (! is_array($rows)) {
            throw new TruckConnectApiException($this->missingRowsMessage($body));
        }

        $machineMap = $this->machineMap();
        $now        = Carbon::now();

        $prepared = [];
        $summary  = [
            'received'   => count($rows),
            'stored'     => 0,
            'duplicates' => 0,
            'unmatched'  => 0,
            'skipped'    => 0,
            'unlocated'  => 0,
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $summary['skipped']++;
                continue;
            }

            $vin = trim((string) Arr::get($row, 'VIN', ''));

            // Without a VIN there is nothing to key the reading on.
            if ($vin === '') {
                $summary['skipped']++;
                continue;
            }

            // The raw vendor timestamp is the dedupe key, so a row without one
            // cannot be stored without risking duplicates on the next poll.
            $gpsTime = $this->nullIfBlank(Arr::get($row, 'GpsTime'));

            if ($gpsTime === null) {
                $summary['skipped']++;
                continue;
            }

            $equipmentNameId = Arr::get($machineMap, strtoupper($vin));

            if ($equipmentNameId === null) {
                $summary['unmatched']++;
            }

            $latitude  = $this->toFloat(Arr::get($row, 'LAT'));
            $longitude = $this->toFloat(Arr::get($row, 'LONG'));

            if ($latitude === null || $longitude === null) {
                $summary['unlocated']++;
            }

            $prepared[] = [
                'vin'               => $vin,
                'equipment_name_id' => $equipmentNameId,

                // Sent as RegistrationId, but observed as a copy of the VIN
                // rather than a number plate. Stored as sent and never used
                // for display.
                'registration_id' => $this->nullIfBlank(Arr::get($row, 'RegistrationId')),
                'device_imei'     => $this->nullIfBlank(Arr::get($row, 'IMEI')),

                'ignition'        => $this->toBool(Arr::get($row, 'IGN')),
                'vehicle_status'  => $this->nullIfBlank(Arr::get($row, 'VehicleStatus')),
                'message_status'  => $this->nullIfBlank(Arr::get($row, 'MessageStatus')),
                'crt'             => $this->nullIfBlank(Arr::get($row, 'CRT')),

                'latitude'        => $latitude,
                'longitude'       => $longitude,
                'heading'         => $this->toFloat(Arr::get($row, 'HEAD')),
                'altitude'        => $this->toFloat(Arr::get($row, 'ALT')),

                'vehicle_speed'   => $this->toFloat(Arr::get($row, 'VehicleSpeed')),
                'engine_rpm'      => $this->toFloat(Arr::get($row, 'RPM')),

                // Stored exactly as sent - unit unconfirmed. See the *_raw
                // columns and the config knobs.
                'odometer_raw'     => $this->toFloat(Arr::get($row, 'Odometer')),
                'fuel_level_raw'   => $this->toFloat(Arr::get($row, 'FuelLevel')),
                'adblue_level_raw' => $this->toFloat(Arr::get($row, 'AdlLevel')),

                // Vendor spells the middle one "HarshBreaking"; do not
                // "correct" the key when reading it.
                'harsh_acceleration' => $this->toInt(Arr::get($row, 'HarshAcceleration')),
                'harsh_braking'      => $this->toInt(Arr::get($row, 'HarshBreaking')),
                'harsh_cornering'    => $this->toInt(Arr::get($row, 'HarshCornering')),

                'gps_time_raw'    => $gpsTime,
                'reported_at'     => $this->reportedAt($gpsTime),

                'raw'             => json_encode($row),
                'created_at'      => $now->toDateTimeString(),
                'updated_at'      => $now->toDateTimeString(),
            ];
        }

        if (! empty($prepared)) {
            $before = TruckConnectReading::count();

            // The unique (vin, gps_time_raw) constraint absorbs vehicles that
            // have not reported since the last poll. Keyed on the raw vendor
            // timestamp rather than reported_at, so answering the timezone
            // question later can never introduce duplicates.
            TruckConnectReading::insertOrIgnore($prepared);

            $summary['stored']     = TruckConnectReading::count() - $before;
            $summary['duplicates'] = count($prepared) - $summary['stored'];
        }

        return $summary;
    }

    /**
     * Resolve GpsTime into the app timezone, or null while the source zone is
     * unconfirmed.
     *
     * GpsTime arrives as a bare "2026-09-01 05:03:56" with no offset and no
     * epoch companion, so the zone it was rendered in cannot be inferred.
     * Guessing would put reported_at up to 5h30m out and silently break
     * staleness and every shift-boundary lookup, exactly as it did on the VECV
     * feed - so nothing is written until config says which zone it is.
     *
     * gps_time_raw always holds the vendor's value, so a null here costs
     * nothing: setting the config later fills these in.
     *
     * @param  string  $gpsTime
     * @return string|null
     */
    protected function reportedAt($gpsTime)
    {
        $sourceZone = config('truckconnect.source_timezone');

        if (empty($sourceZone) || $sourceZone === 'unknown') {
            return null;
        }

        try {
            return Carbon::parse($gpsTime, $sourceZone)
                ->setTimezone(config('app.timezone'))
                ->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Map VIN to machine id.
     *
     * Reads equipment_names.chassis_number - the same master the VECV feeds
     * resolve against, so one registration serves both vendors. Register new
     * machines with telematics:register-chassis.
     *
     * @return array
     */
    protected function machineMap()
    {
        $map = [];

        EquipmentName::query()
            ->select('id', 'chassis_number')
            ->whereNotNull('chassis_number')
            ->get()
            ->each(function ($machine) use (&$map) {
                $chassis = strtoupper(trim((string) $machine->chassis_number));

                if ($chassis !== '') {
                    $map[$chassis] = $machine->id;
                }
            });

        return $map;
    }

    /**
     * Explain a response that carried no rows array.
     *
     * @param  array  $body
     * @return string
     */
    protected function missingRowsMessage(array $body)
    {
        $message = trim((string) Arr::get($body, 'responseMessage'));

        return 'Truck Connect response contained no "' . config('truckconnect.rows_key') . '" array.'
            . ($message !== '' ? ' Message: ' . $this->client->redact($message) . '.' : '')
            . ' Keys present: ' . (empty($body) ? '(none)' : implode(', ', array_keys($body))) . '.';
    }

    /**
     * IGN arrives as "0"/"1". Anything else is unknown rather than false - an
     * unreadable ignition must never be recorded as "switched off".
     *
     * @param  mixed  $value
     * @return bool|null
     */
    protected function toBool($value)
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value === 1;
    }

    /**
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
     * @return int|null
     */
    protected function toInt($value)
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
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
