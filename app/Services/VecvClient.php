<?php

namespace App\Services;

use App\Exceptions\VecvApiException;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Low-level transport for the VECV rFMS Partner API.
 *
 * Owns token acquisition/caching and the header contract. Domain mapping lives
 * in the calling service, not here.
 */
class VecvClient
{
    /**
     * Cache key prefix for per-feed last-successful-fetch timestamps.
     */
    const SYNC_CACHE_PREFIX = 'vecv.last_sync.';

    /**
     * Fetch a bearer token, reusing the cached one until it is close to expiry.
     *
     * @param  bool  $forceRefresh
     * @return string
     *
     * @throws \App\Exceptions\VecvApiException
     */
    public function token($forceRefresh = false)
    {
        $cacheKey = config('vecv.token_cache_key');

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (! empty($cached)) {
                return $cached;
            }
        }

        $apiKey = config('vecv.api_key');
        if (empty($apiKey)) {
            throw new VecvApiException('VECV_API_KEY is not configured.');
        }

        $url = config('vecv.base_url') . config('vecv.endpoints.token');

        try {
            $response = Http::withHeaders([
                'API-KEY' => $apiKey,
                'Accept'  => 'application/json',
            ])->timeout(config('vecv.timeout'))->get($url);
        } catch (\Throwable $e) {
            throw new VecvApiException('Could not reach VECV auth endpoint: ' . $e->getMessage(), null, $e);
        }

        if (! $response->successful()) {
            throw new VecvApiException(
                'VECV auth failed: ' . $this->errorMessage($response->json(), $response->body()),
                $response->status()
            );
        }

        $body = $response->json();

        // status is false when the key is inactive or unknown - the HTTP status
        // is still 200, so the body has to be checked.
        if (! Arr::get($body, 'status')) {
            throw new VecvApiException(
                'VECV auth rejected: ' . Arr::get($body, 'errorMessage', 'unknown reason'),
                $response->status()
            );
        }

        $token = Arr::get($body, 'token');
        if (empty($token)) {
            throw new VecvApiException('VECV auth returned no token.');
        }

        Cache::put($cacheKey, $token, $this->tokenLifetime(Arr::get($body, 'expTime')));

        return $token;
    }

    /**
     * POST to a VECV data endpoint with a valid token, refreshing once on 401.
     *
     * @param  string  $endpoint  Path from config('vecv.endpoints')
     * @param  array   $payload
     * @return array
     *
     * @throws \App\Exceptions\VecvApiException
     */
    public function post($endpoint, array $payload)
    {
        $response = $this->send($endpoint, $payload, $this->token());

        // The gateway checks API-KEY before the bearer token, so a 401 does not
        // always mean the token expired - but refreshing once is cheap.
        if ($response->status() === 401) {
            Cache::forget(config('vecv.token_cache_key'));
            $response = $this->send($endpoint, $payload, $this->token(true));
        }

        if ($response->status() === 409 || $response->status() === 429) {
            throw new VecvApiException('VECV rate limit hit (1 request/minute).', $response->status());
        }

        if (! $response->successful()) {
            throw new VecvApiException(
                'VECV request failed: ' . $this->errorMessage($response->json(), $response->body()),
                $response->status()
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new VecvApiException('VECV returned a non-JSON body.', $response->status());
        }

        // Only a genuinely successful exchange counts. The gateway reports
        // service failures inside a 200 - the alert log does exactly this - so
        // recording every 2xx would show a feed as freshly synced on the
        // strength of an error body.
        if (! (array_key_exists('success', $body) && ! $body['success'])) {
            $this->recordSync($endpoint);
        }

        return $body;
    }

    /**
     * Remember when a feed last came back with real data.
     *
     * Written here rather than in the sync services because this is the one
     * place every call passes through, so a fetch is recorded whether it came
     * from the refresh button, the artisan command or a manual run.
     *
     * Distinct from "when did new readings arrive": a feed can be fetched
     * successfully and store nothing, because no vehicle has reported since
     * last time. This answers "how old is what we are looking at", which is the
     * question a dashboard needs.
     *
     * @param  string  $endpoint
     * @return void
     */
    protected function recordSync($endpoint)
    {
        Cache::forever(self::SYNC_CACHE_PREFIX . $endpoint, Carbon::now()->toDateTimeString());
    }

    /**
     * When a feed was last fetched successfully, or null if never.
     *
     * @param  string  $endpoint
     * @return string|null
     */
    public static function lastSyncedAt($endpoint)
    {
        return Cache::get(self::SYNC_CACHE_PREFIX . $endpoint);
    }

    /**
     * Last successful fetch for every configured feed.
     *
     * @return array
     */
    public static function lastSyncedMap()
    {
        $map = [];

        foreach (array_keys((array) config('vecv.endpoints')) as $endpoint) {
            // The token endpoint is plumbing, not a feed.
            if ($endpoint === 'token') {
                continue;
            }

            $map[$endpoint] = static::lastSyncedAt($endpoint);
        }

        return $map;
    }

    /**
     * Issue the request.
     *
     * @return \Illuminate\Http\Client\Response
     */
    protected function send($endpoint, array $payload, $token)
    {
        $url = config('vecv.base_url') . config('vecv.endpoints.' . $endpoint);

        $headers = [
            'API-KEY'       => config('vecv.api_key'),
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ];

        // Documented as mandatory for the location and fuel endpoints only.
        $clientId = config('vecv.client_id');
        if (! empty($clientId)) {
            $headers['X-IBM-Client-Id'] = $clientId;
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->timeout(config('vecv.timeout'))
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            $this->logCall($endpoint, $url, null, $startedAt, $e->getMessage());

            throw new VecvApiException('Could not reach VECV: ' . $e->getMessage(), null, $e);
        }

        $this->logCall($endpoint, $url, $response->status(), $startedAt, $this->outcome($response));

        return $response;
    }


    /**
     * Record one outbound call to the vecv log channel.
     *
     * Written for every call, successful or not, because the question this log
     * answers - "did pressing refresh actually reach VECV, and which endpoints
     * did it hit?" - cannot be answered from a log that only records failures.
     *
     * @param  string      $endpoint  Config key, e.g. "fuel"
     * @param  string      $url
     * @param  int|null    $status    Null when the request never completed
     * @param  float       $startedAt microtime(true) before the call
     * @param  string|null $outcome
     * @return void
     */
    protected function logCall($endpoint, $url, $status, $startedAt, $outcome = null)
    {
        Log::channel('vecv')->info('VECV call', [
            'endpoint' => $endpoint,
            'path'     => parse_url($url, PHP_URL_PATH),
            'http'     => $status,
            'ms'       => (int) round((microtime(true) - $startedAt) * 1000),

            // Redacted: the API echoes the submitted key back inside its error
            // bodies, and this file is read by people who should not see it.
            'outcome'  => $outcome === null ? null : $this->redact($outcome),
        ]);
    }

    /**
     * A short description of what came back.
     *
     * A 2xx is not proof of success here - the gateway reports service failures
     * inside a 200 body - so the body is inspected rather than the status alone.
     *
     * @param  \Illuminate\Http\Client\Response  $response
     * @return string
     */
    protected function outcome($response)
    {
        if ($response->status() === 409 || $response->status() === 429) {
            return 'rate limited';
        }

        $body = $response->json();

        if (! is_array($body)) {
            return $response->successful() ? 'ok (non-array body)' : 'http error';
        }

        if (array_key_exists('success', $body) && ! $body['success']) {
            return 'rejected: ' . (string) ($body['message'] ?? 'no message');
        }

        if (! $response->successful()) {
            return 'http error: ' . $this->errorMessage($body, $response->body());
        }

        // Row counts make it obvious at a glance whether a call returned data.
        $counts = [];

        foreach ($body as $key => $value) {
            if (is_array($value)) {
                $counts[] = $key . '=' . count($value);
            }
        }

        return 'ok' . (empty($counts) ? '' : ' (' . implode(', ', $counts) . ')');
    }

    /**
     * Cache lifetime for a token, in seconds.
     *
     * expTime is ISO-8601 UTC (every other timestamp in this API is IST).
     * Observed TTL is 24 hours; a buffer is subtracted so a token is never used
     * in the last moments of its life.
     *
     * @param  string|null  $expTime
     * @return int
     */
    protected function tokenLifetime($expTime)
    {
        $buffer = (int) config('vecv.token_expiry_buffer_seconds');

        if (empty($expTime)) {
            return 3600;
        }

        try {
            $seconds = Carbon::parse($expTime)->diffInSeconds(Carbon::now(), false) * -1;
        } catch (\Throwable $e) {
            return 3600;
        }

        $lifetime = $seconds - $buffer;

        // Guard against a clock skew or an already-expired token.
        return $lifetime > 60 ? (int) $lifetime : 60;
    }

    /**
     * Pull a human message out of whichever error envelope came back.
     *
     * Three shapes are in use and they share no common field:
     *   auth      {"timestamp":"<iso>","path":…,"status":…,"error":…,"requestId":…}
     *   gateway   {"success":false,"statusCode":…,"message":…}
     *   internal  {"timestamp":<epoch-ms>,"status":…,"error":…,"path":…}
     *
     * @param  mixed   $body
     * @param  string  $raw
     * @return string
     */
    protected function errorMessage($body, $raw = '')
    {
        if (is_array($body)) {
            foreach (['message', 'errorMessage', 'error'] as $key) {
                $value = Arr::get($body, $key);
                if (! empty($value) && is_string($value)) {
                    return $value;
                }
            }
        }

        return $this->redact($raw !== '' ? $raw : 'no response body');
    }

    /**
     * The API echoes the submitted key back in its error bodies - strip it
     * before anything reaches the log files.
     *
     * @param  string  $text
     * @return string
     */
    public function redact($text)
    {
        $apiKey = config('vecv.api_key');

        if (! empty($apiKey)) {
            $text = str_replace($apiKey, '[REDACTED]', $text);
        }

        return $text;
    }
}
