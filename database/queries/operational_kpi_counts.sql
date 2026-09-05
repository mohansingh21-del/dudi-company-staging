-- ---------------------------------------------------------------------------
-- Operational KPI cross-check
--
-- Mirrors FleetTelematicsDashboardService: latest reading per machine from both
-- vendors, merged, then classified. Run it against the same database the API
-- reads and the counts must match exactly.
--
-- Thresholds are inlined below and must match config:
--   vecv.stale_after_minutes          (VECV_STALE_AFTER_MINUTES)
--   truckconnect.stale_after_minutes  (TRUCKCONNECT_STALE_AFTER_MINUTES)
-- ---------------------------------------------------------------------------

WITH
-- Latest reading per machine, keyed on MAX(id) - rows are insert-only, so the
-- highest id is the most recent fetch. Same rule as latestPerChassis().
latest_vecv AS (
    SELECT r.*
    FROM equipment_fuel_readings r
    JOIN (SELECT MAX(id) AS id FROM equipment_fuel_readings GROUP BY chassis_number) m
      ON m.id = r.id
),
latest_tc AS (
    SELECT r.*
    FROM truck_connect_readings r
    JOIN (SELECT MAX(id) AS id FROM truck_connect_readings GROUP BY vin) m
      ON m.id = r.id
),

-- Both feeds flattened into one shape. Each carries its own staleness
-- threshold, because the two vendors report at different rates.
unified AS (
    SELECT 'vecv' AS source, chassis_number AS chassis, reported_at,
           vehicle_speed, vehicle_status,
           NULL AS ignition,                    -- VECV publishes no ignition flag
           30   AS threshold_minutes
    FROM latest_vecv
    UNION ALL
    SELECT 'truck_connect', vin, reported_at,
           vehicle_speed, vehicle_status,
           ignition,
           30
    FROM latest_tc
),

-- A machine fitted with both vendors' units must be counted once, at its
-- freshest reading. NULLs sort last under DESC, so a row with no timestamp
-- loses to one that has any.
deduped AS (
    SELECT *, ROW_NUMBER() OVER (PARTITION BY UPPER(chassis) ORDER BY reported_at DESC) AS rn
    FROM unified
),
fleet AS (SELECT * FROM deduped WHERE rn = 1),

-- age is absolute, matching Carbon's diffInMinutes.
aged AS (
    SELECT *,
           CASE WHEN reported_at IS NULL THEN NULL
                ELSE ABS(TIMESTAMPDIFF(MINUTE, reported_at, NOW())) END AS age_minutes
    FROM fleet
),

classified AS (
    SELECT source, chassis, reported_at, age_minutes, vehicle_speed, vehicle_status, ignition,
        CASE
            -- No usable timestamp, or too old: offline. Checked first, because
            -- the feeds keep returning the last known speed forever.
            WHEN age_minutes IS NULL                 THEN 'offline'
            WHEN age_minutes > threshold_minutes     THEN 'offline'

            -- Speed missing or negative is broken telemetry, never "stopped".
            WHEN vehicle_speed IS NULL               THEN 'unknown'
            WHEN vehicle_speed < 0                   THEN 'unknown'
            WHEN vehicle_speed > 0                   THEN 'moving'

            -- A real ignition flag wins where one exists (Truck Connect).
            WHEN ignition = 1                        THEN 'stationary'
            WHEN ignition = 0                        THEN 'engine_off'

            -- VECV has none, so its vocabulary stands in for it.
            WHEN UPPER(vehicle_status) = 'IDLING'    THEN 'stationary'
            WHEN UPPER(vehicle_status) = 'STOPPED'   THEN 'engine_off'

            ELSE 'unknown'
        END AS operational_state
    FROM aged
),

-- All five states listed even at zero, because the API always returns all five
-- keys and a missing row would read as a mismatch.
states AS (
    SELECT 'moving' AS operational_state UNION ALL
    SELECT 'stationary'                  UNION ALL
    SELECT 'engine_off'                  UNION ALL
    SELECT 'offline'                     UNION ALL
    SELECT 'unknown'
),
totals AS (SELECT COUNT(*) AS fleet FROM classified)

SELECT
    s.operational_state,
    COALESCE(c.machines, 0) AS machines,

    -- Raw share. The API rounds these with the largest-remainder method so the
    -- five add up to exactly 100, so expect its figures to differ from these by
    -- at most one point - that is the rounding, not a miscount. Compare the
    -- machine counts, not the percentages.
    ROUND(COALESCE(c.machines, 0) * 100.0 / NULLIF(t.fleet, 0), 1) AS pct_raw,
    t.fleet AS fleet_total
FROM states s
CROSS JOIN totals t
LEFT JOIN (
    SELECT operational_state, COUNT(*) AS machines
    FROM classified
    GROUP BY operational_state
) c ON c.operational_state = s.operational_state
ORDER BY FIELD(s.operational_state, 'moving', 'stationary', 'engine_off', 'offline', 'unknown');
