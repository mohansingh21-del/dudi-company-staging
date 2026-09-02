<?php

namespace App\Services;

use App\Models\EquipmentFuelReading;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the live fleet dashboard from VECV telemetry.
 *
 * Reads equipment_fuel_readings only. That feed is a superset of the location
 * feed for everything shown here - it carries fuel, position, speed, odometer,
 * engine hours and status in one row - so using it alone avoids merging two
 * feeds whose readings were taken a minute apart and disagree slightly.
 *
 * Every panel is derived from one snapshot per vehicle (the latest reading),
 * so the whole dashboard can be answered without differencing readings over
 * time. That is what makes it safe to refresh on a button press rather than
 * from a schedule.
 */
class FleetTelematicsDashboardService
{
    /**
     * Headline tiles, the fuel donut and the operational split.
     *
     * @return array
     */
    public function summary()
    {
        $fleet = $this->fleet();

        $online = $fleet->where('is_stale', false);

        $states  = $this->stateCounts($online);
        $avgFuel = $this->averageFuel($fleet);

        return [
            // Headline tiles, flat. Every operational count here is over the
            // ONLINE vehicles only - see operational() for why - while the fuel
            // average is over the whole fleet's last known levels.
            'totals' => [
                'total_vehicles'   => $fleet->count(),
                'online_vehicles'  => $online->count(),
                'offline_vehicles' => $fleet->count() - $online->count(),

                // Share of the fleet currently reporting. Null rather than 0
                // for an empty fleet, so the UI does not render "0% online"
                // when the real answer is "no vehicles".
                'online_pct' => $fleet->count() > 0
                    ? round($online->count() / $fleet->count() * 100, 1)
                    : null,

                'moving_vehicles'     => $states['moving'],
                'stationary_vehicles' => $states['stationary'],
                'engine_off_vehicles' => $states['engine_off'],

                // Vehicles reporting something that cannot be classified -
                // an invalid speed, or a status this feed has not published
                // before. Kept visible rather than folded into engine_off:
                // "we do not know" must never be reported as "switched off".
                'unknown_vehicles'    => $states['unknown'],

                'average_fuel_level'  => $avgFuel,

                // Requires the VECV alert log endpoint, which is not yet
                // returning data. Null means "not available", not "zero
                // events" - the UI must show a dash, never a 0.
                'safety_events'       => null,

                // Feed chassis with no row in the machine master. 6.1 asks for
                // the master to be reconciled against API receipts; this is
                // that gap, expressed as a number to chase.
                'unmatched_vehicles'  => $fleet->whereStrict('machine_id', null)->count(),
            ],

            // Operational state is counted over ONLINE vehicles only. The API
            // has no staleness marker and returns the last known reading
            // forever: one vehicle in the current feed last reported 2.5 days
            // ago and still comes back as MOVING. Counting those would report
            // parked machines as working.
            'operational' => $this->operational($online, $fleet->count()),

            // Fuel, by contrast, is counted over the WHOLE fleet. A parked
            // machine's tank level is still its real tank level, whereas its
            // movement status is meaningless once stale. stale_readings is
            // returned alongside so the UI can qualify the figure.
            'fuel' => $this->fuel($fleet),

            // Not available on this feed. VECV publishes no harsh
            // acceleration/braking/cornering counters on either the fuel or
            // the location endpoint, so the mock-up's "Safety Events" card
            // cannot be built from VECV at all - it needs the Truck Connect
            // feed, which does carry them.
            'safety_events' => null,

            'generated_at' => Carbon::now()->toDateTimeString(),
            'stale_after_minutes' => (int) config('vecv.stale_after_minutes'),
        ];
    }

    /**
     * One row per vehicle for the telemetry table.
     *
     * @param  array  $filters  search, status ('online'|'offline'), limit
     * @return array
     */
    public function vehicles(array $filters = [])
    {
        $rows = $this->fleet();

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';

        if ($search !== '') {
            $rows = $rows->filter(function ($row) use ($search) {
                return stripos($row['chassis_number'], $search) !== false
                    || ($row['dumper_no'] !== null && stripos($row['dumper_no'], $search) !== false);
            });
        }

        if (isset($filters['status']) && in_array($filters['status'], ['online', 'offline'], true)) {
            $rows = $rows->where('is_stale', $filters['status'] === 'offline');
        }

        // Offline first: a vehicle that has stopped reporting is the row an
        // operator needs to act on, and it is the one that sorts last by every
        // other column because all its live values are blank.
        $rows = $rows->sortBy([
            ['is_stale', 'desc'],
            ['dumper_no', 'asc'],
            ['chassis_number', 'asc'],
        ])->values();

        return $rows->all();
    }

    /**
     * The vehicles running lowest on fuel.
     *
     * @param  int|null  $limit
     * @return array
     */
    public function lowestFuel($limit = null)
    {
        $limit = $limit ?: (int) config('vecv.lowest_fuel_limit');

        return $this->fleet()
            // A vehicle with no fuel reading is not "low on fuel", it is
            // unknown. Left in, it would sort to the top of an ascending list
            // and fill the panel with vehicles that have no data at all.
            ->filter(function ($row) {
                return $row['fuel_level_pct'] !== null;
            })
            // Chassis is the tie-break so two vehicles on the same level do
            // not swap places between refreshes.
            ->sortBy([
                ['fuel_level_pct', 'asc'],
                ['chassis_number', 'asc'],
            ])
            ->take($limit)
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The current state of every vehicle: latest reading per chassis, with the
     * dumper number resolved where the vehicle master knows the chassis.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function fleet()
    {
        return EquipmentFuelReading::latestPerChassis()
            // Left join, not a constraint: VECV is the source of truth for
            // which vehicles exist. A chassis missing from the machine master
            // still appears here, with a null dumper number, rather than
            // vanishing from the fleet count.
            //
            // Joined live rather than reading the stored equipment_name_id,
            // so registering a machine fixes the whole dashboard immediately
            // instead of only affecting readings ingested afterwards.
            ->leftJoin('equipment_names', function ($join) {
                $join->on('equipment_names.chassis_number', '=', 'equipment_fuel_readings.chassis_number')
                    ->whereNotNull('equipment_names.chassis_number');
            })
            ->select(
                'equipment_fuel_readings.*',
                'equipment_names.id as machine_id',
                'equipment_names.equipment_name'
            )
            ->get()
            ->map(function ($reading) {
                return $this->presentRow($reading);
            });
    }

    /**
     * Shape one reading for the dashboard.
     *
     * Live values are blanked on a stale reading rather than shown as-is. The
     * feed keeps returning the last known speed and status indefinitely, so
     * displaying them would state that a machine parked since Sunday is doing
     * 12 km/h. Fuel, odometer and engine hours survive staleness - they are
     * the last known true values and do not drift while a machine sits - so
     * they are kept, with age exposed so the UI can qualify them.
     *
     * @param  \App\Models\EquipmentFuelReading  $reading
     * @return array
     */
    protected function presentRow(EquipmentFuelReading $reading)
    {
        $isStale = (bool) $reading->is_stale;
        $fuelPct = $reading->fuel_level_pct === null ? null : (float) $reading->fuel_level_pct;

        return [
            'chassis_number' => $reading->chassis_number,

            // equipment_names.id - the machine this chassis is registered as.
            'machine_id'     => $reading->machine_id,

            // Falls back to the chassis so the table is never blank in the
            // column an operator identifies the machine by. Register the
            // chassis on equipment_names to replace it with the machine name.
            'dumper_no'      => $reading->equipment_name,
            'display_name'   => $reading->equipment_name ?: $reading->chassis_number,

            'connectivity'   => $isStale ? 'offline' : 'online',
            'is_stale'       => $isStale,
            'age_minutes'    => $reading->age_minutes,

            // VECV vocabulary: MOVING / IDLING / STOPPED. Suppressed when
            // stale for the reason above.
            'vehicle_status' => $isStale ? null : $reading->vehicle_status,
            'vehicle_speed'  => $isStale ? null : $this->speed($reading->vehicle_speed),

            // Single classification used by the tiles, the operational split
            // and the table badge, so they cannot disagree. Null when stale.
            'operational_state' => $isStale
                ? null
                : $this->state($this->speed($reading->vehicle_speed), $reading->vehicle_status),

            'fuel_level_pct' => $fuelPct,
            'fuel_level_ltr' => $this->float($reading->fuel_level_ltr),
            'fuel_status'    => $this->bucket($fuelPct),

            'def_level_ltr'  => $this->float($reading->def_level_ltr),

            // Whole kilometres on this feed.
            'odometer_km'    => $this->float($reading->odometer),

            // Not on the Truck Connect feed - VECV's advantage. Utilisation
            // reporting will want this.
            'engine_hours'   => $this->float($reading->engine_operating_hours),

            'latitude'       => $this->float($reading->latitude),
            'longitude'      => $this->float($reading->longitude),

            'last_reported_at' => $reading->reported_at ? $reading->reported_at->toDateTimeString() : null,

            // Columns the mock-up shows that VECV simply does not publish.
            // Returned explicitly as null so the front end renders "-" rather
            // than silently dropping the column and looking complete.
            'ignition'   => null,
            'engine_rpm' => null,
        ];
    }

    /**
     * Moving / idling / stopped counts over the online vehicles.
     *
     * Classification lives in state(); this only tallies it.
     *
     * @param  \Illuminate\Support\Collection  $online
     * @param  int  $fleetTotal
     * @return array
     */
    protected function operational(Collection $online, $fleetTotal)
    {
        $counts = $this->stateCounts($online);

        return [
            'counts'      => $counts,
            'percentages' => $this->distribute($counts, $online->count()),

            // Denominator, stated. Every operational percentage is a share of
            // the online vehicles, not of the whole fleet - without this the
            // numbers look like they should add up to the fleet size.
            'basis'       => 'online',
            'basis_count' => $online->count(),
            'fleet_count' => $fleetTotal,
        ];
    }

    /**
     * The fuel donut and fleet average.
     *
     * @param  \Illuminate\Support\Collection  $fleet
     * @return array
     */
    protected function fuel(Collection $fleet)
    {
        $known = $this->withValidFuel($fleet);

        $counts = [
            'normal'   => $known->where('fuel_status', 'normal')->count(),
            'low'      => $known->where('fuel_status', 'low')->count(),
            'critical' => $known->where('fuel_status', 'critical')->count(),
        ];

        // Vehicles with no usable fuel reading - missing, or outside 0-100.
        // Reported as its own segment so the donut can still total the full
        // fleet: dropping them silently makes the counts disagree with the
        // "total vehicles" tile.
        $unknown = $fleet->count() - $known->count();

        return [
            'counts'      => $counts + ['unknown' => $unknown],
            'percentages' => $this->distribute($counts, $known->count()),

            'average_pct' => $this->averageFuel($fleet),

            'total_litres' => $fleet->sum(function ($row) {
                return $row['fuel_level_ltr'] ?: 0;
            }) ?: null,

            'basis'       => 'all vehicles, last known level',
            'basis_count' => $known->count(),

            // How many of those levels came from a reading older than the
            // staleness threshold, so the UI can footnote the average.
            'stale_readings' => $known->where('is_stale', true)->count(),

            'thresholds' => [
                'normal_min'   => (float) config('vecv.fuel_buckets.normal_min'),
                'critical_max' => (float) config('vecv.fuel_buckets.critical_max'),
            ],
        ];
    }

    /**
     * Classify one vehicle's operational state.
     *
     * Speed decides movement, per the KPI definition and the label the tile
     * carries ("Speed > 0 km/h"). Speed is also vendor-neutral, so this rule
     * will behave identically on the Truck Connect feed.
     *
     * Precedence is deliberate and leaves no gap or overlap:
     *
     *   1. speed missing or negative        -> unknown  (never guess)
     *   2. speed > 0                        -> moving
     *   3. speed = 0 and engine running     -> stationary
     *   4. speed = 0 and engine off         -> engine_off
     *   5. anything else                    -> unknown
     *
     * Steps 3 and 4 are where VECV departs from the written definition, which
     * keys them on an ignition flag. VECV publishes no ignition field at all,
     * on any endpoint. IDLING and STOPPED carry exactly that meaning in its
     * vocabulary - engine running but not travelling, versus shut down - so
     * they stand in for it. An unrecognised status falls to unknown rather
     * than engine_off, honouring the rule that a missing ignition reading must
     * never be reported as "switched off".
     *
     * @param  float|null  $speed   Already validated by speed()
     * @param  string|null $status
     * @return string
     */
    protected function state($speed, $status)
    {
        if ($speed === null) {
            return 'unknown';
        }

        if ($speed > 0) {
            return 'moving';
        }

        if (strcasecmp((string) $status, 'IDLING') === 0) {
            return 'stationary';
        }

        if (strcasecmp((string) $status, 'STOPPED') === 0) {
            return 'engine_off';
        }

        // MOVING at zero speed lands here: the two fields contradict each
        // other, so neither is trusted.
        return 'unknown';
    }

    /**
     * Tally the four operational states across a set of rows.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @return array
     */
    protected function stateCounts(Collection $rows)
    {
        $counts = ['moving' => 0, 'stationary' => 0, 'engine_off' => 0, 'unknown' => 0];

        foreach ($rows as $row) {
            $state = $row['operational_state'];

            if ($state !== null && array_key_exists($state, $counts)) {
                $counts[$state]++;
            } else {
                $counts['unknown']++;
            }
        }

        return $counts;
    }

    /**
     * A speed value, or null when it cannot be trusted.
     *
     * A negative speed is not slow, it is broken telemetry; treating it as 0
     * would quietly file the vehicle under "stationary".
     *
     * @param  mixed  $value
     * @return float|null
     */
    protected function speed($value)
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        $speed = (float) $value;

        return $speed < 0 ? null : $speed;
    }

    /**
     * Rows whose fuel percentage is present and inside 0-100.
     *
     * Anything outside that range is a sensor or mapping fault, not a real
     * tank level. Left in, a single 1000 would drag the fleet average into
     * nonsense while still looking like a plausible number on screen.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @return \Illuminate\Support\Collection
     */
    protected function withValidFuel(Collection $rows)
    {
        return $rows->filter(function ($row) {
            $pct = $row['fuel_level_pct'];

            return $pct !== null && $pct >= 0 && $pct <= 100;
        });
    }

    /**
     * Mean fuel level across vehicles with a valid reading.
     *
     * Vehicles without one are excluded from the denominator, not counted as
     * zero - a machine whose sensor is dead is not a machine with an empty
     * tank. Null when nothing is measurable, so the UI shows a dash.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @return float|null
     */
    protected function averageFuel(Collection $rows)
    {
        $valid = $this->withValidFuel($rows);

        return $valid->count() > 0 ? round($valid->avg('fuel_level_pct'), 1) : null;
    }

    /**
     * Which fuel bucket a percentage falls in.
     *
     * Single source for the donut, the badge and the lowest-fuel list. The
     * boundaries themselves fall in "low", so the three buckets are exhaustive
     * and do not overlap.
     *
     * @param  float|null  $pct
     * @return string|null
     */
    protected function bucket($pct)
    {
        if ($pct === null) {
            return null;
        }

        $normalMin   = (float) config('vecv.fuel_buckets.normal_min');
        $criticalMax = (float) config('vecv.fuel_buckets.critical_max');

        if ($pct > $normalMin) {
            return 'normal';
        }

        return $pct < $criticalMax ? 'critical' : 'low';
    }

    /**
     * Turn counts into whole percentages that add up to exactly 100.
     *
     * Rounding each share independently does not do this - three equal buckets
     * floor to 33/33/33 and the donut legend visibly fails to total 100. The
     * largest remainder method hands the shortfall to the buckets that lost
     * the most in rounding.
     *
     * @param  array  $counts
     * @param  int    $total
     * @return array
     */
    protected function distribute(array $counts, $total)
    {
        if ($total <= 0) {
            return array_map(function () {
                return 0;
            }, $counts);
        }

        $floors     = [];
        $remainders = [];

        foreach ($counts as $key => $count) {
            $exact           = $count / $total * 100;
            $floors[$key]    = (int) floor($exact);
            $remainders[$key] = $exact - $floors[$key];
        }

        $deficit = 100 - array_sum($floors);

        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($deficit <= 0) {
                break;
            }

            $floors[$key]++;
            $deficit--;
        }

        return $floors;
    }

    /**
     * @param  mixed  $value
     * @return float|null
     */
    protected function float($value)
    {
        return $value === null ? null : (float) $value;
    }
}
