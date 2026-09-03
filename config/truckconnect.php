<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Truck Connect telematics API
    |--------------------------------------------------------------------------
    |
    | A second telematics feed alongside VECV rFMS (see config/vecv.php). It is
    | deliberately kept separate rather than folded into the VECV services: one
    | Truck Connect response carries fuel and position together, the field names
    | and status vocabulary differ, and several fields (RPM, ignition, harsh
    | event counters) have no equivalent on the VECV feed.
    |
    | Trimmed for the same reason as the VECV values: a stray space in the .env
    | is invisible in an editor but produces a 404 once it reaches the URL path.
    |
    */

    'base_url' => rtrim(trim(env('TRUCKCONNECT_BASE_URL', 'https://daas.truckonnect.bharatbenz.com')), '/'),

    'endpoints' => [
        // GET, not POST. Returns the whole subscribed fleet in one call - there
        // is no per-VIN request and no chassis list to send, which is why this
        // can be polled far more freely than the VECV feeds.
        'daas' => '/cds/cdsapi/daas',
    ],

    // Sent as the "subscription-key" header. The vendor calls it the primary
    // key.
    'api_key' => trim(env('TRUCKCONNECT_API_KEY', '')),

    // Sent as a query parameter. Identifies which signal subscription to
    // serve - it decides which of the 21 standard signals come back, so a
    // wrong or missing one yields a valid-looking response with fields absent.
    'profile_key' => trim(env('TRUCKCONNECT_PROFILE_KEY', '')),

    'timeout' => (int) env('TRUCKCONNECT_TIMEOUT', 30),

    // Response envelope. Rows arrive under "daas"; success is signalled by
    // responseStatus 200 with the reason in responseMessage.
    'rows_key' => 'daas',

    // The vendor states this endpoint is meant to be called every minute, and
    // documents no rate limit - unlike VECV, which throttles to one request a
    // minute across the whole key. Polling here is therefore cheap, and the
    // 21 signals arrive for every subscribed truck at once.
    'poll_interval_seconds' => (int) env('TRUCKCONNECT_POLL_INTERVAL', 60),

    /*
    |--------------------------------------------------------------------------
    | UNCONFIRMED VENDOR SEMANTICS
    |--------------------------------------------------------------------------
    |
    | Three values in the payload are ambiguous and are pending confirmation
    | from the Truck Connect team. Until each is answered its knob stays
    | 'unknown', and the matching accessor on TruckConnectReading returns null
    | rather than guessing.
    |
    | This is the whole point of the design: the raw vendor value is stored
    | verbatim in *_raw columns and interpreted only at read time. When an
    | answer arrives, set the knob here - every row already stored, and every
    | row stored from now on, becomes correct at once. Nothing needs a data
    | migration and nothing needs re-fetching.
    |
    | Never default these to a guess. A wrong unit does not error; it produces a
    | plausible-looking number on a dashboard, which is far worse.
    |
    */

    // Observed: "30297536.0" on a vehicle that has plainly not done 30 million
    // kilometres, so metres is the likely answer - but likely is not confirmed.
    // Values: 'metres' | 'kilometres' | 'unknown'
    'odometer_unit' => env('TRUCKCONNECT_ODOMETER_UNIT', 'unknown'),

    // Observed: "69.59". Reads like a percentage, but the VECV feed carries
    // both fuelLevelInPer and fuelLevelInLtr, so litres is equally plausible.
    //
    // If this turns out to be 'litres', a percentage CANNOT be derived from it
    // alone - tank capacity per vehicle would have to be recorded first. The
    // fuel gauge, the Normal/Low/Critical buckets and the fleet average all
    // depend on this answer.
    //
    // Values: 'percent' | 'litres' | 'unknown'
    'fuel_level_unit' => env('TRUCKCONNECT_FUEL_LEVEL_UNIT', 'unknown'),

    // AdBlue/DEF. Same ambiguity, same rule.
    // Values: 'percent' | 'litres' | 'unknown'
    'adblue_level_unit' => env('TRUCKCONNECT_ADBLUE_LEVEL_UNIT', 'unknown'),

    // GpsTime arrives as a bare "2026-09-01 05:03:56" with no offset and no
    // epoch companion, so the zone it was rendered in cannot be inferred.
    //
    // This app stores wall-clock in config('app.timezone') (Asia/Kolkata)
    // everywhere - created_at, shift times, Carbon::now(). Assuming the wrong
    // source zone puts reported_at 5h30m out and silently breaks staleness and
    // any shift-boundary lookup, exactly as it did on the VECV feed.
    //
    // Values: an IANA zone such as 'Asia/Kolkata' or 'UTC' | 'unknown'
    'source_timezone' => env('TRUCKCONNECT_SOURCE_TIMEZONE', 'unknown'),

    /*
    |--------------------------------------------------------------------------
    | Harsh event counters
    |--------------------------------------------------------------------------
    |
    | HarshAcceleration / HarshBreaking (vendor's spelling) / HarshCornering all
    | read "0" in the sample, so it is not yet known whether they are cumulative
    | totals or a flag for the current message.
    |
    | No config knob is offered because neither reading can be converted into
    | the other - the difference decides whether a "today" count is possible at
    | all, not how to scale a number:
    |
    |   cumulative -> a day's count is the difference against the first reading
    |                 of the day, so at least two readings per day must exist.
    |   per-message -> only events landing in the exact moment of a fetch are
    |                 ever seen, so an on-demand fetch misses nearly all of them.
    |
    | Either way a "Safety Events (Today)" tile needs readings captured through
    | the day, which an on-click fetch alone does not provide.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Staleness
    |--------------------------------------------------------------------------
    |
    | Matches the VECV default. MessageStatus ("H" in the sample) may turn out
    | to be a live/history marker, which would make this a backstop rather than
    | the only signal - but until that is confirmed, age derived from
    | reported_at is the only trustworthy test.
    |
    */

    'stale_after_minutes' => (int) env('TRUCKCONNECT_STALE_AFTER_MINUTES', 30),
];
