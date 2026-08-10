<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VECV rFMS Partner API
    |--------------------------------------------------------------------------
    |
    | Telematics feed for Eicher/VECV vehicles. Credentials are issued by VECV
    | during onboarding. Every value is trimmed: a stray space in the .env is
    | invisible in an editor but produces a 404 once it reaches the URL path.
    |
    */

    'base_url' => rtrim(trim(env('VECV_BASE_URL', 'https://partnerapi.vecv.net')), '/'),

    'api_key' => trim(env('VECV_API_KEY', '')),

    // Not returned by the token endpoint despite the vendor doc claiming it is.
    // Only obtainable from VECV onboarding, so it must live in config.
    'client_id' => trim(env('VECV_CLIENT_ID', '')),

    'timeout' => (int) env('VECV_TIMEOUT', 30),

    'endpoints' => [
        // Vendor spells this "genrateToken". Not a typo on our side - the
        // correctly spelled path returns 401.
        'token' => '/service-gateway/secure/genrateToken',
        'fuel'  => '/service-gateway/vehicle/v1/getlivedata/fuelData',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token
    |--------------------------------------------------------------------------
    |
    | Observed TTL is 24 hours. The response carries expTime, so the cache is
    | driven off that rather than a fixed lifetime, minus a safety buffer.
    |
    */

    'token_cache_key' => 'vecv.token',

    'token_expiry_buffer_seconds' => (int) env('VECV_TOKEN_BUFFER', 300),

    /*
    |--------------------------------------------------------------------------
    | Sync
    |--------------------------------------------------------------------------
    |
    | The fuel endpoint is limited to 1 request per minute and returns 409 (not
    | 429) when exceeded. One clientId call covers the whole fleet, so a 15
    | minute cadence uses ~7% of the budget.
    |
    | Boundary precision is capped by this interval: a reading can never be
    | fresher than the last poll. At the observed ~8.6 L/hr burn rate, 15 min
    | means ~2 L of error at each shift boundary. Hourly ('0 * * * *') is
    | viable for reporting totals but too coarse to detect siphoning.
    |
    */

    'fuel_sync_cron' => env('VECV_FUEL_SYNC_CRON', '*/15 * * * *'),

    /*
    |--------------------------------------------------------------------------
    | Staleness
    |--------------------------------------------------------------------------
    |
    | The API returns the last known reading for a vehicle with no staleness
    | marker of any kind - a truck that has not reported for 31 hours still
    | comes back as "MOVING" at speed. Age must be derived from epochTime, and
    | anything beyond this threshold must not be treated as live.
    |
    */

    'stale_after_minutes' => (int) env('VECV_STALE_AFTER_MINUTES', 30),
];
