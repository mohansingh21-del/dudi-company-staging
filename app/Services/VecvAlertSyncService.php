<?php

namespace App\Services;

use App\Exceptions\VecvApiException;
use App\Models\VecvAlert;
use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * Pulls driving-behaviour, fuel and device alerts from the VECV alert log.
 *
 * Two things about this endpoint are worth knowing before changing anything
 * here, because both fail quietly rather than loudly:
 *
 *   1. The date fields are eventStartDate / eventEndDate. Every other dated
 *      VECV endpoint uses startDate / endDate; sending those returns HTTP 200
 *      with a generic "service not responding" body that reads as a vendor
 *      outage rather than a rejected request.
 *
 *   2. A rejected request still comes back HTTP 200, with an EMPTY alertLog and
 *      the reason in errorMessage. It is indistinguishable from "no alerts"
 *      unless errorMessage is read - which is how a 3-day range looks like a
 *      quiet fleet instead of a range that exceeded the 48-hour cap.
 */
class VecvAlertSyncService extends VecvSyncService
{
    /**
     * Fetch and store alerts for a set of machines over a date range.
     *
     * @param  array        $chassisNumbers  Defaults to every chassis seen in telemetry.
     * @param  string|null  $from            Y-m-d; defaults to the configured lookback.
     * @param  string|null  $to              Y-m-d; defaults to today.
     * @return array
     *
     * @throws \App\Exceptions\VecvApiException
     */
    public function sync(array $chassisNumbers = [], $from = null, $to = null)
    {
        $chassis = ! empty($chassisNumbers)
            ? $this->clean($chassisNumbers)
            : $this->fleetChassis();

        if (empty($chassis)) {
            throw new VecvApiException(
                'No chassis numbers to request. This endpoint takes no clientId, so it cannot '
                . 'discover the fleet on its own. Run vecv:sync-fuel first, or pass --chassis.'
            );
        }

        list($start, $end) = $this->range($from, $to);

        $windows   = $this->windows($start, $end);
        $machines  = $this->machineMap();
        $chunkSize = max(1, (int) config('vecv.alerts_chunk'));
        $gap       = max(0, (int) config('vecv.alerts_request_gap_seconds'));
        $now       = Carbon::now()->toDateTimeString();

        $summary = [
            'requested' => count($chassis),
            'windows'   => count($windows),
            'batches'   => 0,
            'received'  => 0,
            'stored'    => 0,
            'duplicates' => 0,
            'unmatched' => 0,
            'skipped'   => 0,
            'from'      => $start,
            'to'        => $end,
        ];

        $prepared = [];

        foreach ($windows as $window) {
            foreach (array_chunk($chassis, $chunkSize) as $batch) {
                // Space every request after the first: a second call inside the
                // same minute is refused before the first has been processed.
                if ($summary['batches'] > 0 && $gap > 0) {
                    sleep($gap);
                }

                $summary['batches']++;

                $body = $this->client->post('alerts', [
                    'chassisNo'      => array_values($batch),
                    'eventStartDate' => $window[0] . ' 00:00:00',
                    'eventEndDate'   => $window[1] . ' 23:59:59',
                ]);

                $rows = Arr::get($body, 'alertLog');

                if (! is_array($rows)) {
                    throw new VecvApiException($this->rejectionMessage($body));
                }

                // An empty log with a real errorMessage is a rejection wearing
                // a success's clothes. Treated as an error, because silently
                // recording "no alerts" would hide a broken request forever.
                if (empty($rows) && $this->rejected($body)) {
                    throw new VecvApiException($this->rejectionMessage($body));
                }

                $summary['received'] += count($rows);

                foreach ($rows as $row) {
                    $mapped = $this->map($row, $machines, $now, $summary);

                    if ($mapped !== null) {
                        $prepared[] = $mapped;
                    }
                }
            }
        }

        if (! empty($prepared)) {
            $before = VecvAlert::count();

            // The natural key absorbs re-reads of a window already fetched.
            VecvAlert::insertOrIgnore($prepared);

            $summary['stored']     = VecvAlert::count() - $before;
            $summary['duplicates'] = count($prepared) - $summary['stored'];
        }

        return $summary;
    }

    /**
     * Shape one alert for storage.
     *
     * @param  mixed  $row
     * @param  array  $machines
     * @param  string $now
     * @param  array  $summary  Mutated in place.
     * @return array|null
     */
    protected function map($row, array $machines, $now, array &$summary)
    {
        if (! is_array($row)) {
            $summary['skipped']++;

            return null;
        }

        $chassis = $this->nullIfBlank(Arr::get($row, 'chassisNo'));
        $subType = $this->nullIfBlank(Arr::get($row, 'alertSubTypeId'));
        $time    = $this->nullIfBlank(Arr::get($row, 'alertTime'));

        // These three are the identity of an alert. Without all of them it
        // cannot be stored without risking a duplicate on the next run.
        if ($chassis === null || $subType === null || $time === null) {
            $summary['skipped']++;

            return null;
        }

        $equipmentNameId = Arr::get($machines, strtoupper($chassis));

        if ($equipmentNameId === null) {
            $summary['unmatched']++;
        }

        return [
            'chassis_number'    => $chassis,
            'equipment_name_id' => $equipmentNameId,
            'customer_id'       => $this->nullIfBlank(Arr::get($row, 'customerId')),
            'reg_no'            => $this->nullIfBlank(Arr::get($row, 'regNo')),
            'alert_type'        => (string) Arr::get($row, 'alertType', 'Unknown'),
            'alert_sub_type_id' => $subType,
            'alert_sub_type'    => (string) Arr::get($row, 'alertSubType', $subType),

            // Absent entirely on some types - a device disconnection has no
            // measurement - so these must be read null-safely.
            'alert_value'       => $this->toFloat(Arr::get($row, 'alertValue')),
            'alert_unit'        => $this->nullIfBlank(Arr::get($row, 'alertUnit')),

            'alert_time_raw'    => $time,
            'alerted_at'        => $this->alertedAt($time),

            'latitude'          => $this->toFloat(Arr::get($row, 'lat')),
            'longitude'         => $this->toFloat(Arr::get($row, 'lng')),

            'raw'               => json_encode($row),
            'created_at'        => $now,
            'updated_at'        => $now,
        ];
    }

    /**
     * Parse alertTime into the app timezone.
     *
     * The feed sends no offset, and it is IST rather than UTC: in a live pull
     * the newest alert was three minutes old against the IST clock, where
     * reading it as UTC would have put it five and a half hours in the future.
     * Since the app stores wall-clock in IST everywhere, it is taken as sent.
     *
     * The format varies - some types carry milliseconds - so it is parsed
     * rather than matched against a fixed mask.
     *
     * @param  string  $time
     * @return string|null
     */
    protected function alertedAt($time)
    {
        try {
            return Carbon::parse($time, config('app.timezone'))->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether a 200 response is actually a refusal.
     *
     * errorMessage is the string "NONE" on success.
     *
     * @param  array  $body
     * @return bool
     */
    protected function rejected(array $body)
    {
        $error = trim((string) Arr::get($body, 'errorMessage'));

        return $error !== '' && strcasecmp($error, 'NONE') !== 0;
    }

    /**
     * @param  array  $body
     * @return string
     */
    protected function rejectionMessage(array $body)
    {
        $error = trim((string) Arr::get($body, 'errorMessage'));

        if ($error !== '' && strcasecmp($error, 'NONE') !== 0) {
            return 'VECV alert request rejected: ' . $this->client->redact($error);
        }

        return 'VECV alert response contained no alertLog array. Keys present: '
            . (empty($body) ? '(none)' : implode(', ', array_keys($body))) . '.';
    }

    /**
     * Split a range into windows the vendor will accept.
     *
     * Both ends are inclusive, so a 2-day maximum spans start .. start+1.
     * Each window costs its own rate-limit slot.
     *
     * @param  string  $start
     * @param  string  $end
     * @return array
     */
    protected function windows($start, $end)
    {
        $maxDays = max(1, (int) config('vecv.alerts_max_range_days'));

        $cursor  = Carbon::parse($start);
        $last    = Carbon::parse($end);
        $windows = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $windowEnd = $cursor->copy()->addDays($maxDays - 1);

            if ($windowEnd->greaterThan($last)) {
                $windowEnd = $last->copy();
            }

            $windows[] = [$cursor->toDateString(), $windowEnd->toDateString()];

            $cursor = $windowEnd->copy()->addDay();
        }

        return $windows;
    }

    /**
     * Resolve the requested range, defaulting to the configured lookback.
     *
     * @param  string|null  $from
     * @param  string|null  $to
     * @return array
     *
     * @throws \App\Exceptions\VecvApiException
     */
    protected function range($from, $to)
    {
        try {
            $end   = $to !== null ? Carbon::parse($to) : Carbon::today();
            $start = $from !== null
                ? Carbon::parse($from)
                : $end->copy()->subDays(max(0, (int) config('vecv.alerts_lookback_days')));
        } catch (\Throwable $e) {
            throw new VecvApiException('Could not parse the alert date range: ' . $e->getMessage());
        }

        if ($start->greaterThan($end)) {
            throw new VecvApiException(
                'Alert start date (' . $start->toDateString() . ') is after the end date ('
                . $end->toDateString() . ').'
            );
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * Every chassis this system has seen telemetry for.
     *
     * The endpoint takes no clientId, so the fleet has to come from somewhere
     * local.
     *
     * @return array
     */
    protected function fleetChassis()
    {
        return $this->clean(
            \App\Models\EquipmentFuelReading::distinct()->pluck('chassis_number')
                ->merge(\App\Models\EquipmentLocationReading::distinct()->pluck('chassis_number'))
                ->all()
        );
    }

    /**
     * @param  array  $values
     * @return array
     */
    protected function clean(array $values)
    {
        $cleaned = array_filter(array_map(function ($value) {
            return trim((string) $value);
        }, $values), function ($value) {
            return $value !== '';
        });

        return array_values(array_unique($cleaned));
    }
}
