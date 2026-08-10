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

        return $body;
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

        try {
            return Http::withHeaders($headers)
                ->timeout(config('vecv.timeout'))
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            throw new VecvApiException('Could not reach VECV: ' . $e->getMessage(), null, $e);
        }
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
