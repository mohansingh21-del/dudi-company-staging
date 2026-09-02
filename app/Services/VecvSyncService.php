<?php

namespace App\Services;

use App\Exceptions\VecvApiException;
use App\Models\EquipmentName;
use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * Shared ingest behaviour for the VECV rFMS live-data endpoints.
 *
 * Every endpoint takes the same request body (clientId / regNo / chassisNo),
 * keys its rows on chassisNo, and stamps them with the same epochTime +
 * lastUpdated pair. Only the endpoint name, the response wrapper key and the
 * column mapping differ, so those are all a subclass has to supply.
 */
abstract class VecvSyncService
{
    /** @var \App\Services\VecvClient */
    protected $client;

    public function __construct(VecvClient $client)
    {
        $this->client = $client;
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
                . 'The live-data endpoints require at least one of clientId, regNo or chassisNo.'
            );
        }

        return ['clientId' => $clientId];
    }

    /**
     * Map chassis number to machine id.
     *
     * Reads equipment_names.chassis_number, added 2026-09-02. Before that this
     * matched against equipment_names.equipment_name - the column holding the
     * label an operator reads ("Dump-3") - so it resolved only while somebody
     * kept typing chassis numbers into the name field, and every reading landed
     * with a null equipment_name_id the moment anyone gave a machine a readable
     * name. Do not point this back at equipment_name.
     *
     * Machines with no chassis (dozers, pumps, anything off the telematics
     * feed) are skipped rather than mapped to an empty key.
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
     * Resolve the instant a reading was taken, in the application timezone.
     *
     * epochTime is a true UTC epoch and is preferred; lastUpdated is the same
     * instant pre-rendered as YmdHis in IST, so it is only a fallback.
     *
     * Both are converted to config('app.timezone') before being handed back.
     * This app runs on Asia/Kolkata and stores wall-clock in that zone
     * everywhere else - created_at, shift times, Carbon::now(). Writing UTC
     * into reported_at would leave it 5h30m adrift of every value it is
     * compared against, which silently breaks staleness, the live() scope and
     * shift-boundary lookups rather than failing outright.
     *
     * @param  array  $row
     * @return \Carbon\Carbon|null
     */
    protected function reportedAt(array $row)
    {
        $timezone = config('app.timezone');

        $epoch = Arr::get($row, 'epochTime');

        if (! empty($epoch) && is_numeric($epoch)) {
            return Carbon::createFromTimestampUTC((int) $epoch)->setTimezone($timezone);
        }

        // Delivered as a JSON number, so cast before matching.
        $lastUpdated = Arr::get($row, 'lastUpdated');

        if (! empty($lastUpdated) && preg_match('/^\d{14}$/', (string) $lastUpdated)) {
            try {
                return Carbon::createFromFormat('YmdHis', (string) $lastUpdated, 'Asia/Kolkata')
                    ->setTimezone($timezone);
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
