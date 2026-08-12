<?php

namespace App\Services;

use App\Exceptions\VecvApiException;
use App\Models\EquipmentLocationReading;
use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * Pulls live position and odometer telemetry from VECV and stores it as
 * immutable readings.
 *
 * One clientId request returns the whole provisioned fleet, which keeps the
 * endpoint's 1-request-per-minute limit comfortably out of the way.
 */
class VecvLocationSyncService extends VecvSyncService
{
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
        $body = $this->client->post('location', $this->payload($chassisNumbers));

        $rows = Arr::get($body, 'locationData');

        if (! is_array($rows)) {
            throw new VecvApiException($this->missingRowsMessage($body));
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
            'unlocated'  => 0,
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $summary['skipped']++;
                continue;
            }

            $chassis = trim((string) Arr::get($row, 'chassisNo', ''));

            // Without a chassis there is nothing to key the reading on.
            if ($chassis === '') {
                $summary['skipped']++;
                continue;
            }

            // A vehicle with no data is returned as an object of defaults and
            // nulls rather than being omitted, so it has no usable timestamp
            // and is dropped here.
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

            $latitude  = $this->toFloat(Arr::get($row, 'latitude'));
            $longitude = $this->toFloat(Arr::get($row, 'longitude'));

            // A timestamped row can still carry no fix. It is kept - odometer
            // is often present without one - but counted so a fleet-wide GPS
            // outage is visible in the run output.
            if ($latitude === null || $longitude === null) {
                $summary['unlocated']++;
            }

            $prepared[] = [
                'chassis_number'    => $chassis,
                'equipment_name_id' => $equipmentNameId,
                'reg_no'            => $this->nullIfBlank(Arr::get($row, 'regNo')),
                'vehicle_status'    => $this->nullIfBlank(Arr::get($row, 'vehicleStatus')),
                'latitude'          => $latitude,
                'longitude'         => $longitude,
                'vehicle_speed'     => $this->toFloat(Arr::get($row, 'vehicleSpeed')),
                'odometer'          => $this->toFloat(Arr::get($row, 'odometer')),
                'vehicle_direction' => $this->toFloat(Arr::get($row, 'vehicleDirection')),
                'device_id'         => $this->nullIfBlank(Arr::get($row, 'deviceId')),
                'reported_at'       => $reportedAt->toDateTimeString(),
                'raw'               => json_encode($row),
                'created_at'        => $now->toDateTimeString(),
                'updated_at'        => $now->toDateTimeString(),
            ];
        }

        if (! empty($prepared)) {
            $before = EquipmentLocationReading::count();

            // The unique (chassis_number, reported_at) constraint absorbs
            // vehicles that have not reported since the last poll.
            EquipmentLocationReading::insertOrIgnore($prepared);

            $summary['stored']     = EquipmentLocationReading::count() - $before;
            $summary['duplicates'] = count($prepared) - $summary['stored'];
        }

        return $summary;
    }

    /**
     * Explain a response that carried no locationData array.
     *
     * A failure here still arrives as HTTP 200 with the reason in
     * errorMessage, which the client only reads on non-2xx responses. The
     * field is the string "None" on success, so it is only worth quoting when
     * it says something else.
     *
     * @param  array  $body
     * @return string
     */
    protected function missingRowsMessage(array $body)
    {
        $error = trim((string) Arr::get($body, 'errorMessage'));

        if ($error !== '' && strcasecmp($error, 'None') !== 0) {
            return 'VECV location request rejected: ' . $this->client->redact($error);
        }

        return 'VECV location response contained no locationData array. Keys present: '
            . (empty($body) ? '(none)' : implode(', ', array_keys($body))) . '.';
    }
}
