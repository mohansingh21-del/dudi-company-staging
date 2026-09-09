<?php

namespace App\Services;

use App\Exceptions\VecvApiException;
use App\Models\EquipmentFuelReading;
use App\Models\EquipmentLocationReading;
use App\Models\VecvServiceHistory;
use App\Models\VecvServiceHistoryItem;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Pulls dealer workshop job cards from the VECV Service History API.
 *
 * Differs from the telemetry syncs in three ways that shape the whole class:
 *
 *   1. There is no clientId parameter, so the fleet cannot be fetched in one
 *      call - chassis numbers must be enumerated and sent in batches.
 *   2. The records are mutable. A job card is amended after it opens (an
 *      invoice appears once the work is billed), so rows are updated in place
 *      on job_card_number instead of being inserted immutably.
 *   3. Each record nests a jobCardLineItems array, which becomes child rows.
 */
class VecvServiceHistorySyncService extends VecvSyncService
{
    /**
     * Fetch and store job cards for a set of vehicles over a date range.
     *
     * @param  array   $chassisNumbers  Defaults to every chassis seen in telemetry.
     * @param  string|null  $from       Y-m-d; defaults to the configured lookback.
     * @param  string|null  $to         Y-m-d; defaults to today.
     * @return array   Summary counts for the caller to report.
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
                . 'discover the fleet on its own; it relies on chassis numbers already seen in '
                . 'equipment_fuel_readings or equipment_location_readings. Run vecv:sync-fuel or '
                . 'vecv:sync-location first, or pass --chassis explicitly.'
            );
        }

        list($start, $end) = $this->range($from, $to);

        $machineMap = $this->machineMap();
        $chunkSize  = max(1, (int) config('vecv.service_history_chunk'));

        $windows = $this->windows($start, $end);

        $summary = [
            'requested'      => count($chassis),
            'windows'        => count($windows),
            'batches'        => 0,
            'received'       => 0,
            'created'        => 0,
            'updated'        => 0,
            'items'          => 0,
            'unmatched'      => 0,
            'skipped'        => 0,
            'from'           => $start,
            'to'             => $end,
        ];

        foreach ($windows as $window) {
            foreach (array_chunk($chassis, $chunkSize) as $batch) {
                // Space every request after the first. This is the only sync
                // that can need more than one call - a wide date range splits
                // into windows, and a large fleet into chunks - and VECV
                // refuses a second request inside the same minute, so firing
                // them back to back guarantees everything past the first is
                // rate limited.
                //
                // A run is therefore minutes long when the range is wide. That
                // is acceptable because this only ever runs from a background
                // pass or the console, never inside an HTTP request.
                if ($summary['batches'] > 0) {
                    sleep(max(0, (int) config('vecv.service_history_request_gap_seconds')));
                }

                $summary['batches']++;

                $body = $this->client->post('service_history', [
                    'chassisNo' => array_values($batch),
                    'startDate' => $window[0] . ' 00:00:00',
                    'endDate'   => $window[1] . ' 23:59:59',
                ]);

                $rows = Arr::get($body, 'serviceHistory');

                if (! is_array($rows)) {
                    throw new VecvApiException($this->missingRowsMessage($body));
                }

                $summary['received'] += count($rows);

                foreach ($rows as $row) {
                    $this->store($row, $machineMap, $summary);
                }
            }
        }

        return $summary;
    }

    /**
     * Persist one job card and its line items.
     *
     * @param  mixed  $row
     * @param  array  $machineMap
     * @param  array  $summary  Mutated in place.
     * @return void
     */
    protected function store($row, array $machineMap, array &$summary)
    {
        if (! is_array($row)) {
            $summary['skipped']++;

            return;
        }

        $jobCard = $this->nullIfBlank(Arr::get($row, 'jobCardNumber'));
        $chassis = $this->nullIfBlank(Arr::get($row, 'vehicleChassisNo'));

        // A vehicle with no history comes back as an object of defaults and
        // nulls rather than being omitted. Without both keys there is nothing
        // to store or to key on.
        if ($jobCard === null || $chassis === null) {
            $summary['skipped']++;

            return;
        }

        $equipmentNameId = Arr::get($machineMap, strtoupper($chassis));

        if ($equipmentNameId === null) {
            $summary['unmatched']++;
        }

        $attributes = [
            'chassis_number'      => $chassis,
            'equipment_name_id'   => $equipmentNameId,
            'registration_no'     => $this->nullIfBlank(Arr::get($row, 'registrationNo')),
            'dealer_name'         => $this->nullIfBlank(Arr::get($row, 'dealerName')),
            'model_description'   => $this->nullIfBlank(Arr::get($row, 'modelDescription')),
            // Vendor spells this key all lowercase; do not "correct" it.
            'order_type'          => $this->nullIfBlank(Arr::get($row, 'ordertypedescription')),
            'invoice_number'      => $this->nullIfBlank(Arr::get($row, 'invoiceNumber')),
            'odometer'            => $this->toFloat(Arr::get($row, 'odometer')),
            'lube_value'          => $this->toFloat(Arr::get($row, 'lubeTranValue')),
            'labour_value'        => $this->toFloat(Arr::get($row, 'labourTranValue')),
            'parts_value'         => $this->toFloat(Arr::get($row, 'partsValueAmount')),
            'total_cost_customer' => $this->toFloat(Arr::get($row, 'totalCostCust')),
            'job_card_open_date'  => $this->openDate(Arr::get($row, 'jobCardOpenDate')),
            'raw'                 => json_encode($row),
        ];

        DB::transaction(function () use ($jobCard, $attributes, $row, &$summary) {
            $history = VecvServiceHistory::where('job_card_number', $jobCard)->first();

            if ($history === null) {
                $history = VecvServiceHistory::create(
                    array_merge($attributes, ['job_card_number' => $jobCard])
                );
                $summary['created']++;
            } else {
                $history->fill($attributes)->save();
                $summary['updated']++;
            }

            $summary['items'] += $this->storeItems($history, Arr::get($row, 'jobCardLineItems'));
        });
    }

    /**
     * Replace a job card's line items with the set just received.
     *
     * Replaced rather than upserted because a line can be removed from a job
     * card between reads; upserting alone would leave the deleted line behind
     * forever. The whole operation runs inside the caller's transaction.
     *
     * @param  \App\Models\VecvServiceHistory  $history
     * @param  mixed  $lines
     * @return int  Number of items written.
     */
    protected function storeItems(VecvServiceHistory $history, $lines)
    {
        if (! is_array($lines)) {
            return 0;
        }

        $prepared = [];
        $now      = Carbon::now()->toDateTimeString();

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $rowId = $this->nullIfBlank(Arr::get($line, 'rowId'));

            // row_id is the unique key; a line without one cannot be stored
            // without risking duplicates on the next run.
            if ($rowId === null) {
                continue;
            }

            $prepared[] = [
                'vecv_service_history_id'    => $history->id,
                'row_id'                     => $rowId,
                'job_description'            => $this->nullIfBlank(Arr::get($line, 'jobDescription')),
                'job_id_description'         => $this->nullIfBlank(Arr::get($line, 'jobIdDescription')),
                'action'                     => $this->nullIfBlank(Arr::get($line, 'action')),
                'observation'                => $this->nullIfBlank(Arr::get($line, 'observation')),
                'material_code'              => $this->nullIfBlank(Arr::get($line, 'matnr')),
                'material_type'              => $this->nullIfBlank(Arr::get($line, 'matnrType')),
                'job_type'                   => $this->nullIfBlank(Arr::get($line, 'jobTypeBezei')),
                'part_qty'                   => $this->toFloat(Arr::get($line, 'partQty')),
                'part_total_amount'          => $this->toFloat(Arr::get($line, 'partTotalAmt')),
                'part_total_amount_with_gst' => $this->toFloat(Arr::get($line, 'partTotalAmtInGst')),
                'raw'                        => json_encode($line),
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ];
        }

        VecvServiceHistoryItem::where('vecv_service_history_id', $history->id)->delete();

        if (! empty($prepared)) {
            VecvServiceHistoryItem::insert($prepared);
        }

        return count($prepared);
    }

    /**
     * Every chassis number this system has seen telemetry for.
     *
     * The service history endpoint has no clientId, so unlike fuel and
     * location it cannot ask "everything for this account" - the fleet has to
     * come from somewhere local. The telemetry tables are used rather than
     * equipment_names because that table does not currently hold chassis
     * numbers.
     *
     * @return array
     */
    protected function fleetChassis()
    {
        $fuel     = EquipmentFuelReading::distinct()->pluck('chassis_number')->all();
        $location = EquipmentLocationReading::distinct()->pluck('chassis_number')->all();

        return $this->clean(array_merge($fuel, $location));
    }

    /**
     * Trim, drop blanks and de-duplicate a list of chassis numbers.
     *
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

    /**
     * Resolve the requested date range, defaulting to the configured lookback.
     *
     * @param  string|null  $from
     * @param  string|null  $to
     * @return array  [start, end] as Y-m-d
     *
     * @throws \App\Exceptions\VecvApiException
     */
    protected function range($from, $to)
    {
        try {
            $end   = $to !== null ? Carbon::parse($to) : Carbon::today();
            $start = $from !== null
                ? Carbon::parse($from)
                : $end->copy()->subDays(max(0, (int) config('vecv.service_history_lookback_days')));
        } catch (\Throwable $e) {
            throw new VecvApiException('Could not parse the service history date range: ' . $e->getMessage());
        }

        if ($start->greaterThan($end)) {
            throw new VecvApiException(
                'Service history start date (' . $start->toDateString() . ') is after the end date ('
                . $end->toDateString() . ').'
            );
        }

        // The vendor refuses any date more than 30 days old with "Dates should
        // not exceed 30 days (720 hours) from today's date". Caught here so an
        // impossible range fails immediately with a message that says why,
        // instead of burning a rate-limit slot to be told the same thing in
        // vendor wording.
        $oldest = Carbon::today()->subDays(max(1, (int) config('vecv.service_history_max_age_days')));

        if ($start->lessThan($oldest)) {
            throw new VecvApiException(
                'Service history start date (' . $start->toDateString() . ') is older than the '
                . config('vecv.service_history_max_age_days') . ' days VECV allows. The earliest '
                . 'reachable date is ' . $oldest->toDateString() . '. Older job cards can only come '
                . 'from what has already been stored locally.'
            );
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * Split a date range into windows the vendor will accept.
     *
     * VECV rejects any request wider than 48 hours with "The date range should
     * not exceed 2 days (48 hours)" - a plain error, not a truncation, so an
     * over-wide request returns nothing at all rather than a partial result.
     *
     * Each window is a separate request and therefore a separate rate-limit
     * slot: at one request per minute per key, a 90 day backfill is 45 windows
     * and three quarters of an hour. Routine runs stay inside one window by
     * keeping service_history_lookback_days at the maximum.
     *
     * Both ends are inclusive, matching how they are sent (00:00:00 to
     * 23:59:59), so a 2 day maximum spans start .. start+1.
     *
     * @param  string  $start  Y-m-d
     * @param  string  $end    Y-m-d
     * @return array   List of [from, to] pairs, oldest first
     */
    protected function windows($start, $end)
    {
        $maxDays = max(1, (int) config('vecv.service_history_max_range_days'));

        $cursor = Carbon::parse($start);
        $last   = Carbon::parse($end);

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
     * Date component of jobCardOpenDate.
     *
     * Sent as "2023-08-22T00:00:00.000+00:00" - midnight UTC standing in for a
     * plain calendar date. Shifting that into IST would move it to 05:30 and
     * imply a precision the field does not carry, so the date is taken as
     * sent.
     *
     * @param  mixed  $value
     * @return string|null
     */
    protected function openDate($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Explain a response that carried no serviceHistory array.
     *
     * @param  array  $body
     * @return string
     */
    protected function missingRowsMessage(array $body)
    {
        $error = trim((string) Arr::get($body, 'errorMessage'));

        if ($error !== '' && strcasecmp($error, 'None') !== 0) {
            return 'VECV service history request rejected: ' . $this->client->redact($error);
        }

        return 'VECV service history response contained no serviceHistory array. Keys present: '
            . (empty($body) ? '(none)' : implode(', ', array_keys($body))) . '.';
    }
}
