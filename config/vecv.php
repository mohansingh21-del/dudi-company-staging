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
        'token'    => '/service-gateway/secure/genrateToken',
        'fuel'     => '/service-gateway/vehicle/v1/getlivedata/fuelData',
        'location' => '/service-gateway/vehicle/v1/getlivedata/location',

        // Not under /vehicle/v1/getlivedata - this one is served from the
        // datalake and behaves differently: no clientId, date-ranged, and the
        // records it returns are mutable rather than point-in-time.
        'service_history' => '/service-gateway/datalake/serviceHistory/getServiceHistory',

        // Driving-behaviour, fuel and predictive-uptime alerts - the only VECV
        // source for harsh braking/acceleration, over-speeding and the like,
        // none of which appear on the fuel or location feeds.
        //
        // Vendor spells this "getGetAlertLogs", with the doubled Get. Verified,
        // not a typo on our side: this path rate-limits (429), while the
        // sensibly spelled getAlertLogs returns 401.
        //
        // Takes chassisNo + startDate/endDate and no clientId, so - like
        // service history - the fleet cannot be fetched in one call.
        'alerts' => '/service-gateway/alertLog/getGetAlertLogs',
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
    | Offset by one minute from the fuel cron (:01/:16/:31/:46 against
    | :00/:15/:30/:45). The vendor doc rate limits "per the API-KEY
    | configuration" without saying whether the 1-request-per-minute budget is
    | per key or per endpoint. If it is per key, syncing both in the same
    | minute would 409 one of them every cycle; the stagger is correct either
    | way and costs nothing.
    */

    'location_sync_cron' => env('VECV_LOCATION_SYNC_CRON', '1-59/15 * * * *'),

    /*
    |--------------------------------------------------------------------------
    | Service history
    |--------------------------------------------------------------------------
    |
    | Workshop job cards, not telemetry. They change on a scale of days, so a
    | nightly run is ample - 03:10 keeps it clear of the :00/:15/:30/:45 and
    | :01/:16/:31/:46 telemetry slots.
    |
    | This endpoint takes no clientId, so the fleet cannot be requested in one
    | call the way fuel and location can: chassis numbers have to be
    | enumerated. They are discovered from the telemetry tables rather than
    | from equipment_names, which does not currently hold chassis numbers.
    |
    */

    'service_history_sync_cron' => env('VECV_SERVICE_HISTORY_SYNC_CRON', '10 3 * * *'),

    // How far back each run looks. Job cards are amended after opening (an
    // invoice is attached once the work is billed), so a wider window re-reads
    // and updates records already stored rather than only catching new ones.
    //
    // Kept at the vendor's per-request maximum so a routine run costs a single
    // request. Anything larger is split into windows of
    // service_history_max_range_days and each window costs its own
    // rate-limit slot - a 90 day backfill is 45 minutes of wall clock, so ask
    // for it deliberately with --from/--to rather than making it the default.
    // Default 1, not 2: the range is inclusive at both ends, so a lookback of 1
    // spans today and yesterday - exactly the vendor's 2 day maximum, and
    // exactly ONE request. A lookback of 2 spans three calendar days, which
    // splits into two windows and therefore two calls, and the second is
    // guaranteed to be rate limited.
    'service_history_lookback_days' => (int) env('VECV_SERVICE_HISTORY_LOOKBACK_DAYS', 1),

    // Pause between successive service history requests within one sync. This
    // endpoint is the only one that can need more than a single call - a wide
    // range splits into windows, and many chassis split into chunks - and
    // without spacing the second call is refused before the first has even
    // been processed.
    'service_history_request_gap_seconds' => (int) env('VECV_SERVICE_HISTORY_REQUEST_GAP', 60),

    // Hard vendor limit, discovered 2026-09-02: a wider range is rejected with
    // "The date range should not exceed 2 days (48 hours)". The default
    // lookback was 90 days until then, which meant this sync had never once
    // succeeded - it failed identically on every run and the error was only
    // visible in the log.
    'service_history_max_range_days' => (int) env('VECV_SERVICE_HISTORY_MAX_RANGE_DAYS', 2),

    // A second, separate vendor limit found the same day: "Dates should not
    // exceed 30 days (720 hours) from today's date". So 30 days is ALL the
    // history this endpoint will ever give up - a job card older than that is
    // unreachable no matter how the range is split. Anything needing a longer
    // record has to be accumulated locally by syncing regularly, which is the
    // one thing an on-demand-only setup does not do.
    'service_history_max_age_days' => (int) env('VECV_SERVICE_HISTORY_MAX_AGE_DAYS', 30),

    // Chassis numbers per request. The vendor documents no cap on the list, so
    // this is a self-imposed limit to keep request bodies sane; it also bounds
    // how much is lost if one batch is rejected.
    'service_history_chunk' => (int) env('VECV_SERVICE_HISTORY_CHUNK', 25),

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

    /*
    |--------------------------------------------------------------------------
    | On-demand refresh
    |--------------------------------------------------------------------------
    |
    | The dashboard refresh button walks every feed one call at a time, because
    | VECV throttles by API key: a successful call is followed by a 429 on the
    | next endpoint whatever it is. Four feeds therefore need four windows, and
    | no single HTTP request should be held open that long.
    |
    | So one POST runs one feed and reports what is left. The caller polls until
    | nothing is pending. A feed that is rate limited stays pending and is
    | retried on a later call - never skipped, because a skipped feed is a
    | silent hole in the data that nothing downstream would reveal.
    |
    */

    // The refresh button now spans both telematics vendors, so this list is
    // wider than the VECV feeds it sits beside. Order matters: the two that
    // the dashboard actually renders from come first, so the screen is correct
    // within seconds and everything after it only adds detail.
    'refresh_feeds' => ['truck_connect', 'fuel', 'location', 'service_history', 'alerts'],

    // Feeds that do NOT share the VECV rate-limit budget and so need no
    // cooldown before or after them. Truck Connect is a different vendor, a
    // different key and a different host, and its own documentation asks for a
    // call every minute - waiting a VECV window before it would add a minute
    // to every pass for no reason at all.
    'refresh_unthrottled_feeds' => ['truck_connect'],

    // Minimum gap between two outbound calls. Held on our side so a too-early
    // click is answered instantly instead of spending a rate-limit slot to be
    // told no.
    'refresh_cooldown_seconds' => (int) env('VECV_REFRESH_COOLDOWN', 60),

    // Attempts per feed before a cycle gives up on it and moves on. Without a
    // cap, a feed that is genuinely unavailable - the alert log, currently -
    // would hold a cycle open indefinitely.
    'refresh_max_attempts' => (int) env('VECV_REFRESH_MAX_ATTEMPTS', 3),

    // A cycle left untouched this long is treated as abandoned, so a new click
    // starts fresh rather than resuming something from hours ago.
    'refresh_cycle_ttl_minutes' => (int) env('VECV_REFRESH_CYCLE_TTL', 30),

    // PHP binary used to launch the background pass. PHP_BINARY is the CLI
    // binary on the command line but the FPM one under a web request, which
    // cannot always run artisan - so it is overridable. Set VECV_PHP_BINARY to
    // an absolute path if the refresh button reports that it could not start.
    'php_binary' => env('VECV_PHP_BINARY', PHP_BINARY),

    /*
    |--------------------------------------------------------------------------
    | Fleet dashboard
    |--------------------------------------------------------------------------
    |
    | Fuel bucket boundaries, as a percentage of tank. Kept here so the donut,
    | the "lowest fuel" list and the per-vehicle status badge all read the same
    | numbers - the mock-up they came from disagreed with itself, labelling 22%
    | Critical in one panel and Low in another.
    |
    | Read as: Normal is above normal_min; Low is normal_min down to and
    | including critical_max; Critical is below critical_max. The boundaries
    | themselves (40 and 20) therefore both fall in Low, leaving no gap and no
    | overlap.
    |
    */

    'fuel_buckets' => [
        'normal_min'   => (float) env('VECV_FUEL_NORMAL_MIN', 40),
        'critical_max' => (float) env('VECV_FUEL_CRITICAL_MAX', 20),
    ],

    /*
    | Rows returned by the "lowest fuel" panel.
    */

    'lowest_fuel_limit' => (int) env('VECV_LOWEST_FUEL_LIMIT', 5),
];
