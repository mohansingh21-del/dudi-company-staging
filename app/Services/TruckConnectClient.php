<?php

namespace App\Services;

use App\Exceptions\TruckConnectApiException;
use Illuminate\Support\Arr;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Low-level transport for the BharatBenz Truckonnect DaaS API.
 *
 * Simpler than the VECV client in every way that matters: one GET, no token
 * exchange, no per-vehicle request body. The subscription key goes in a header
 * and the profile key - which decides which of the 21 signals are served - in
 * the query string. One call returns the whole subscribed fleet.
 *
 * Domain mapping lives in the sync service, not here.
 */
class TruckConnectClient
{
    /**
     * Cache key prefix for last-successful-fetch timestamps.
     */
    const SYNC_CACHE_PREFIX = 'truckconnect.last_sync.';

    /**
     * Fetch the current signal snapshot for every subscribed truck.
     *
     * @return array
     *
     * @throws \App\Exceptions\TruckConnectApiException
     */
    public function daas()
    {
        $apiKey = config('truckconnect.api_key');

        if (empty($apiKey)) {
            throw new TruckConnectApiException('TRUCKCONNECT_API_KEY is not configured.');
        }

        $profileKey = config('truckconnect.profile_key');

        if (empty($profileKey)) {
            throw new TruckConnectApiException(
                'TRUCKCONNECT_PROFILE_KEY is not configured. Without it the API cannot tell which '
                . 'signal subscription to serve.'
            );
        }

        $url = config('truckconnect.base_url') . config('truckconnect.endpoints.daas');

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders([
                'Content-Type'     => 'application/json',
                'subscription-key' => $apiKey,
                'Accept'           => 'application/json',
            ])
                ->timeout(config('truckconnect.timeout'))
                ->get($url, ['profileKey' => $profileKey]);
        } catch (\Throwable $e) {
            $this->logCall(null, $startedAt, $e->getMessage());

            throw new TruckConnectApiException('Could not reach Truck Connect: ' . $e->getMessage(), null, $e);
        }

        $body = $response->json();

        if (! $response->successful()) {
            $this->logCall($response->status(), $startedAt, $this->errorMessage($body, $response->body()));

            throw new TruckConnectApiException(
                'Truck Connect request failed: ' . $this->errorMessage($body, $response->body()),
                $response->status()
            );
        }

        if (! is_array($body)) {
            $this->logCall($response->status(), $startedAt, 'non-JSON body');

            throw new TruckConnectApiException('Truck Connect returned a non-JSON body.', $response->status());
        }

        // The envelope carries its own status. Like the VECV gateway, a failure
        // can arrive inside an HTTP 200, so the body is what decides.
        $status = Arr::get($body, 'responseStatus');

        if ($status !== null && (int) $status !== 200) {
            $message = (string) Arr::get($body, 'responseMessage', 'no message');

            $this->logCall($response->status(), $startedAt, 'rejected: ' . $message);

            throw new TruckConnectApiException(
                'Truck Connect rejected the request: ' . $message,
                (int) $status
            );
        }

        $rows = Arr::get($body, config('truckconnect.rows_key'));

        $this->logCall($response->status(), $startedAt, is_array($rows)
            ? 'ok (' . config('truckconnect.rows_key') . '=' . count($rows) . ')'
            : 'ok (no rows array)');

        $this->recordSync();

        return $body;
    }

    /**
     * When the feed was last fetched successfully, or null if never.
     *
     * @return string|null
     */
    public static function lastSyncedAt()
    {
        return Cache::get(self::SYNC_CACHE_PREFIX . 'daas');
    }

    /**
     * @return void
     */
    protected function recordSync()
    {
        Cache::forever(self::SYNC_CACHE_PREFIX . 'daas', Carbon::now()->toDateTimeString());
    }

    /**
     * Record one outbound call.
     *
     * Written for every call, successful or not: the question this answers -
     * "did the refresh actually reach Truck Connect, and what came back?" -
     * cannot be answered from a log that only records failures.
     *
     * @param  int|null    $status
     * @param  float       $startedAt
     * @param  string|null $outcome
     * @return void
     */
    protected function logCall($status, $startedAt, $outcome = null)
    {
        Log::channel('vecv')->info('Truck Connect call', [
            'endpoint' => 'daas',
            'http'     => $status,
            'ms'       => (int) round((microtime(true) - $startedAt) * 1000),
            'outcome'  => $outcome === null ? null : $this->redact($outcome),
        ]);
    }

    /**
     * Pull a human message out of whichever error envelope came back.
     *
     * @param  mixed   $body
     * @param  string  $raw
     * @return string
     */
    protected function errorMessage($body, $raw = '')
    {
        if (is_array($body)) {
            foreach (['responseMessage', 'message', 'error'] as $key) {
                $value = Arr::get($body, $key);

                if (! empty($value) && is_string($value)) {
                    return $value;
                }
            }
        }

        return $this->redact($raw !== '' ? $raw : 'no response body');
    }

    /**
     * Strip the subscription key before anything reaches the log files.
     *
     * @param  string  $text
     * @return string
     */
    public function redact($text)
    {
        foreach (['api_key', 'profile_key'] as $secret) {
            $value = config('truckconnect.' . $secret);

            if (! empty($value)) {
                $text = str_replace($value, '[REDACTED]', $text);
            }
        }

        return $text;
    }
}
