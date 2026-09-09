<?php

namespace App\Console\Commands;

use App\Exceptions\VecvApiException;
use App\Services\VecvClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Discovery tool for the VECV alert log endpoint.
 *
 * As of 2026-09-02 that endpoint answers every request with HTTP 200 and a
 * body of {"success":false,"statusCode":500,"message":"An error occurred and
 * service not responding..."}.
 *
 * Nothing in the request is wrong: the path rate-limits (429) while the
 * correctly spelled getAlertLogs returns 401, the same key and token fetch fuel
 * telemetry fine, and dropping X-IBM-Client-Id, switching to date-only
 * timestamps, narrowing the range and changing chassis all produce the
 * identical error.
 *
 * The likely cause is configuration rather than an outage. The vendor doc
 * states that alerts must first be enabled per vehicle on the "My Alerts" page
 * of the My Eicher portal, with optional thresholds; a backend that finds no
 * alert configuration for a chassis and fails instead of returning an empty
 * list would look exactly like this.
 *
 * Note that alerts are generated from the moment they are configured, so even
 * once this works, a date range that predates the configuration will legitimately
 * come back empty.
 *
 * The response shape has therefore never been observed, and no mapping or table
 * has been written for it - guessing a schema for an unseen payload is how a
 * migration ends up needing a backfill.
 *
 * Run this after enabling alerts in the portal. It prints the envelope and the
 * first row so the ingest can be written against something real.
 */
class ProbeVecvAlertsCommand extends Command
{
    protected $signature = 'vecv:probe-alerts
                            {--chassis= : Chassis number to query; defaults to one seen in telemetry}
                            {--days=7 : How far back to look}';

    protected $description = 'Probe the VECV alert log endpoint and print its response shape';

    public function handle(VecvClient $client)
    {
        $chassis = $this->option('chassis')
            ?: optional(\App\Models\EquipmentFuelReading::query()->orderBy('id')->first())->chassis_number;

        if (empty($chassis)) {
            $this->error('No chassis available. Run vecv:sync-fuel first, or pass --chassis.');

            return 1;
        }

        $end   = Carbon::now();
        $start = $end->copy()->subDays(max(1, (int) $this->option('days')));

        $this->line('POST ' . config('vecv.endpoints.alerts'));
        $this->line('  chassis: ' . $chassis);
        $this->line('  range  : ' . $start->toDateTimeString() . ' -> ' . $end->toDateTimeString());
        $this->newLine();

        try {
            $body = $client->post('alerts', [
                'chassisNo' => [$chassis],
                'eventStartDate' => $start->toDateTimeString(),
                'eventEndDate'   => $end->toDateTimeString(),
            ]);
        } catch (VecvApiException $e) {
            if ($e->isRateLimited()) {
                $this->warn('Rate limited - wait a minute and run again.');

                return 0;
            }

            $this->error($e->getMessage());

            return 1;
        }

        // The gateway reports service failures inside a 200, so the body has to
        // be inspected rather than trusting the HTTP status.
        if (array_key_exists('success', $body) && ! $body['success']) {
            $this->error('Still failing upstream: ' . ($body['message'] ?? 'no message'));
            $this->line('statusCode: ' . ($body['statusCode'] ?? '-'));
            $this->newLine();
            $this->newLine();
            $this->line('The request itself is fine. Check that alerts are enabled for this');
            $this->line('chassis on the "My Alerts" page of the My Eicher portal - the API');
            $this->line('returns this error when a vehicle has no alert configuration.');
            $this->line('If they are already enabled there, raise it with VECV support.');

            return 1;
        }

        $this->info('Response received. Envelope keys: ' . implode(', ', array_keys($body)));
        $this->newLine();

        foreach ($body as $key => $value) {
            if (! is_array($value)) {
                $this->line($key . ' => ' . json_encode($value));
                continue;
            }

            $this->info($key . ' => array(' . count($value) . ')');

            $first = reset($value);

            if (is_array($first)) {
                $this->line('  row keys: ' . implode(', ', array_keys($first)));
                $this->newLine();
                $this->line(json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        return 0;
    }
}
