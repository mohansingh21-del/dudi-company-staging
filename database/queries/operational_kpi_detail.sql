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

-- Per-machine detail: every input that decides the bucket, plus the reason.
-- Use this to answer "why is this machine counted as X".
totals AS (SELECT COUNT(*) AS fleet FROM classified)

SELECT
    c.source,
    c.chassis,
    c.reported_at,
    c.age_minutes,
    CASE WHEN c.age_minutes IS NULL OR c.age_minutes > 30 THEN 'offline' ELSE 'online' END AS connectivity,
    c.vehicle_speed,
    c.vehicle_status AS vendor_status,
    c.ignition,
    c.operational_state,
    CASE
        WHEN c.age_minutes IS NULL             THEN 'no timestamp'
        WHEN c.age_minutes > 30                THEN CONCAT('age ', c.age_minutes, ' > 30 min')
        WHEN c.vehicle_speed IS NULL           THEN 'speed missing'
        WHEN c.vehicle_speed < 0               THEN 'speed negative'
        WHEN c.vehicle_speed > 0               THEN 'speed > 0'
        WHEN c.ignition = 1                    THEN 'speed 0 + ignition on'
        WHEN c.ignition = 0                    THEN 'speed 0 + ignition off'
        WHEN UPPER(c.vehicle_status) = 'IDLING'  THEN 'speed 0 + IDLING'
        WHEN UPPER(c.vehicle_status) = 'STOPPED' THEN 'speed 0 + STOPPED'
        ELSE 'unrecognised status'
    END AS reason
FROM classified c
CROSS JOIN totals t
ORDER BY FIELD(c.operational_state, 'moving', 'stationary', 'engine_off', 'unknown', 'offline'),
         c.age_minutes;
