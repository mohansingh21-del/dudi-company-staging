<?php

namespace App\Services;

use App\Models\EquipmentFuelReading;
use App\Models\TruckConnectReading;
use App\Models\VecvAlert;
use App\Services\FleetRefreshRunner;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    public function summary(array $filters = [])
    {
        $fleet = $this->fleet($filters);

        $online = $fleet->where('is_stale', false);

        $states  = $this->stateCounts($fleet);
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

                // Same number as offline_vehicles above, repeated here so the
                // four operational tiles can be read as one set that sums to
                // the fleet.
                'data_unavailable'    => $states['offline'],

                'average_fuel_level'  => $avgFuel,

                // Requires the VECV alert log endpoint, which is not yet
                // returning data. Null means "not available", not "zero
                // events" - the UI must show a dash, never a 0.
                'safety_events'       => $this->safetyEvents($filters)['total'],

                // Feed chassis with no row in the machine master. 6.1 asks for
                // the master to be reconciled against API receipts; this is
                // that gap, expressed as a number to chase.
                'unmatched_vehicles'  => $fleet->whereStrict('machine_id', null)->count(),
            ],

            // Counted across the whole fleet, with "offline" as one of the
            // categories. The API has no staleness marker and returns the last
            // known reading forever - one vehicle in the current feed last
            // reported days ago and still comes back as MOVING - so a stale
            // machine is counted as offline rather than as whatever it was
            // doing when it last spoke.
            'operational' => $this->operational($fleet),

            // Fuel, by contrast, is counted over the WHOLE fleet. A parked
            // machine's tank level is still its real tank level, whereas its
            // movement status is meaningless once stale. stale_readings is
            // returned alongside so the UI can qualify the figure.
            'fuel' => $this->fuel($fleet),

            'safety_events' => $this->safetyEvents($filters),

            // When this response was computed. Not the same as when the data
            // was fetched - the GETs never call VECV, so a page refreshed at
            // 4pm can be showing telemetry pulled at 11am.
            'generated_at' => Carbon::now()->toDateTimeString(),

            // When the last refresh actually FINISHED pulling from the feeds.
            // This is the one to show as "Last refreshed" - the GETs never call
            // VECV, so a page opened at 4pm can be showing telemetry pulled at
            // 11am, and only this says which.
            //
            // The finish, not the press: a pass takes minutes, so a press that
            // is still walking the feeds has refreshed nothing yet. It holds
            // the previous value until the new pass completes, so the field is
            // never briefly wrong.
            //
            // Null only until the first refresh completes anywhere. Note a
            // refresh does not guarantee new readings: a feed can be fetched
            // successfully and store nothing because no vehicle has reported
            // since. Per-vehicle freshness is age_minutes on each row.
            'last_refreshed_at' => FleetRefreshRunner::lastFinishedAt(),

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
        $rows = $this->fleet($filters);

        // Offline first: a vehicle that has stopped reporting is the row an
        // operator needs to act on, and it is the one that sorts last by every
        // other column because all its live values are blank.
        $rows = $rows->sortBy([
            ['is_stale', 'desc'],
            ['dumper_no', 'asc'],
            ['chassis_number', 'asc'],
        ])->values();

        return $this->paginate($rows, $filters);
    }

    /**
     * Slice a fleet collection into a page.
     *
     * Paginated here rather than in the database because the fleet is assembled
     * in memory from two vendors' feeds - there is no single query to put a
     * LIMIT on. The whole fleet is a few dozen rows, so the cost of sorting it
     * all and slicing is nothing.
     *
     * Passing no limit returns every row, matching how the rest of this API
     * behaves, and still carries a pagination block so a caller never has to
     * branch on whether one is present.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @param  array  $filters
     * @return array
     */
    protected function paginate(Collection $rows, array $filters)
    {
        $total = $rows->count();

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;

        // No limit asked for: one page holding everything. last_page is 1, not
        // the row count - passing the total there says there are 39 more pages
        // to fetch and hands the caller a next_page_url that returns nothing.
        if ($limit < 1) {
            return [
                'data'       => $rows->all(),
                'pagination' => $this->pagination($total, $total ?: 1, 1, 1),
            ];
        }

        $lastPage = max(1, (int) ceil($total / $limit));

        // Clamped rather than trusted. A page beyond the end would otherwise
        // return an empty list that looks like "no vehicles" instead of "you
        // asked past the end".
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $page = min($page, $lastPage);

        return [
            'data'       => $rows->forPage($page, $limit)->values()->all(),
            'pagination' => $this->pagination($total, $limit, $page, $lastPage),
        ];
    }

    /**
     * The pagination block, in the shape the rest of this API uses.
     *
     * @param  int  $total
     * @param  int  $perPage
     * @param  int  $page
     * @param  int  $lastPage
     * @return array
     */
    protected function pagination($total, $perPage, $page, $lastPage)
    {
        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to   = $total === 0 ? 0 : min($page * $perPage, $total);

        // Carry the whole query string forward, not just page and limit -
        // following a next link must not silently drop the filters the caller
        // is looking at and hand back a different fleet.
        $query = request() ? request()->query() : [];

        $url = function ($target) use ($perPage, $query) {
            return url()->current() . '?' . http_build_query(
                array_merge($query, ['page' => $target, 'limit' => $perPage])
            );
        };

        return [
            'total'              => $total,
            'current_page'       => $page,
            'per_page'           => $perPage,
            'last_page'          => $lastPage,
            'from'               => $from,
            'to'                 => $to,
            'next_page_url'      => $page < $lastPage ? $url($page + 1) : null,
            'previous_page_url'  => $page > 1 ? $url($page - 1) : null,
        ];
    }

    /**
     * The operations row: the operational split and today's safety events.
     *
     * @return array
     */
    public function operations(array $filters = [])
    {
        return [
            'operational'   => $this->operational($this->fleet($filters)),
            'safety_events' => $this->safetyEvents($filters),
        ];
    }

    /**
     * Driving-behaviour events over the window.
     *
     * Comes from the VECV alert log, which publishes discrete events - so these
     * are real counts, not something differenced out of snapshots, and events
     * landing between two refreshes are not lost. That is why this reads alerts
     * rather than the Truck Connect harsh counters, which are per-message flags
     * and have measured 0 on every machine so far; those are still reported
     * alongside, so a change in them is visible.
     *
     * @param  array  $filters
     * @return array
     */
    public function safetyEvents(array $filters = [])
    {
        $range = $this->range($filters) ?: [Carbon::today(), Carbon::today()->endOfDay()];

        $query = VecvAlert::query()
            ->with('machine')
            ->whereBetween('alerted_at', [$range[0], $range[1]])
            ->where('alert_type', 'Driving Behaviour');

        if (! empty($filters['machine_id'])) {
            $query->where('equipment_name_id', (int) $filters['machine_id']);
        }

        // Narrowed here as well as on the fleet snapshot below, so a search
        // cannot leave the two halves of this panel describing different sets
        // of machines - one vehicle in the operational split beside an
        // events list covering the whole fleet.
        $this->applyAlertSearch($query, $filters);

        $events = $query->get();

        $fleet = $this->fleet($filters);

        return [
            // True because there is now a feed publishing these. It does not
            // promise the numbers are non-zero.
            'available' => true,

            'from' => $range[0]->toDateString(),
            'to'   => $range[1]->toDateString(),

            // Keyed by the vendor's own sub-type ids, so a category the account
            // subscribes to later appears here without a code change rather
            // than being silently dropped.
            'counts' => $events->countBy('alert_sub_type_id')->all(),

            // Named breakdown for display, largest first.
            'breakdown' => $this->alertSubTypeBreakdown($events),

            // VECV alerts only, matching 'counts' and 'breakdown' above.
            // by_vehicle below spans both vendors, so its rows sum to this
            // only while the Truck Connect counters stay at zero.
            'total' => $events->count(),

            // "Events by Dumper (Top 5)" by default; View All asks for the
            // whole fleet. Both vendors, each row carrying its 'source' - the
            // counts above are VECV-only, so without the Truck Connect rows
            // here the panel would silently be about a quarter of the fleet.
            'by_vehicle' => $this->safetyEventsByMachine(
                $events,
                $fleet,
                $range,
                $filters,
                isset($filters['limit']) ? $filters['limit'] : null
            ),

            // Only VECV machines raise these. Stated so a quiet card is not
            // read as a quiet fleet when most of it is not being watched.
            'basis'       => 'vecv',
            'basis_count' => $fleet->where('source', 'vecv')->count(),
            'fleet_count' => $fleet->count(),

            // The Truck Connect signal for the same idea, kept visible. These
            // are per-message flags rather than counters, so they are summed
            // across the window - correct for flags, and wrong if they ever
            // turn out cumulative, which would show as an implausibly large
            // number rather than an error.
            'truck_connect_harsh' => $this->truckConnectHarsh($range, $filters),
        ];
    }

    /**
     * Harsh-event flags from the Truck Connect feed over the window.
     *
     * @param  array  $range
     * @param  array  $filters
     * @return array
     */
    protected function truckConnectHarsh(array $range, array $filters)
    {
        $readings = TruckConnectReading::query()
            ->leftJoin('equipment_names', function ($join) {
                $join->on('equipment_names.chassis_number', '=', 'truck_connect_readings.vin')
                    ->whereNotNull('equipment_names.chassis_number');
            })
            ->whereBetween('truck_connect_readings.reported_at', [$range[0], $range[1]])
            ->when(! empty($filters['machine_id']), function ($q) use ($filters) {
                $q->where('equipment_names.id', (int) $filters['machine_id']);
            })
            ->tap(function ($q) use ($filters) {
                $this->applyTruckConnectSearch($q, $filters);
            })
            ->select(
                'truck_connect_readings.harsh_braking',
                'truck_connect_readings.harsh_acceleration',
                'truck_connect_readings.harsh_cornering'
            )
            ->get();

        return [
            'harsh_braking'      => (int) $readings->sum('harsh_braking'),
            'harsh_acceleration' => (int) $readings->sum('harsh_acceleration'),
            'harsh_cornering'    => (int) $readings->sum('harsh_cornering'),

            // How many readings the figure was built from. Roughly one per
            // machine means almost nothing was captured between refreshes, so
            // the total understates reality rather than the fleet behaving.
            'readings_in_range'  => $readings->count(),
        ];
    }

    /**
     * The alerts panel: counts by severity and type, the worst machines, and
     * the Recent Alerts list.
     *
     * Backed by the VECV alert log, which unlike the telemetry feeds carries
     * discrete events - so a count over a window is a real count rather than
     * something differenced out of snapshots, and events between refreshes are
     * not lost. Truck Connect machines contribute nothing here; its harsh
     * counters are a separate, and so far always-zero, signal.
     *
     * @param  array  $filters
     * @return array
     */
    public function alerts(array $filters = [])
    {
        $range = $this->range($filters) ?: [Carbon::today(), Carbon::today()->endOfDay()];

        $query = VecvAlert::query()
            ->with('machine')
            ->whereBetween('alerted_at', [$range[0], $range[1]]);

        if (! empty($filters['machine_id'])) {
            $query->where('equipment_name_id', (int) $filters['machine_id']);
        }

        if (! empty($filters['alert_type'])) {
            $query->where('alert_type', $filters['alert_type']);
        }

        $this->applyAlertSearch($query, $filters);

        $alerts = $query->orderByDesc('alerted_at')->get();

        // Severity is ours, not the vendor's - applied in the model so the
        // list, the tiles and any badge cannot disagree about it.
        if (! empty($filters['severity'])) {
            $alerts = $alerts->where('severity', $filters['severity'])->values();
        }

        // Page size for the Recent Alerts list. Always paginated - an unpaged
        // window can hold hundreds of alerts, and nothing on screen shows them
        // all at once.
        $perPage = isset($filters['limit']) && (int) $filters['limit'] > 0
            ? (int) $filters['limit']
            : (int) config('vecv.recent_alerts_limit');

        // Only the list is paged. The tiles and breakdowns below are computed
        // over every matching alert, so "Showing 1 to 5 of 24" stays true and
        // the counts do not change as the reader turns pages.
        $page = $this->paginate($alerts, ['limit' => $perPage, 'page' => $filters['page'] ?? null]);

        return [
            'totals' => [
                'total'    => $alerts->count(),
                'critical' => $alerts->where('severity', 'critical')->count(),
                'warning'  => $alerts->where('severity', 'warning')->count(),
                'info'     => $alerts->where('severity', 'info')->count(),

                // How many machines raised anything at all. A high alert count
                // from one machine is a different problem from the same count
                // spread across the fleet.
                'machines_affected' => $alerts->pluck('equipment_name_id')->filter()->unique()->count(),
            ],

            'by_type'     => $this->countBy($alerts, 'alert_type'),
            'by_sub_type' => $this->alertSubTypeBreakdown($alerts),
            // No limit passed through: on this endpoint 'limit' already pages
            // the Recent Alerts list below, so it must not silently resize
            // this one too.
            'by_machine'  => $this->alertsByMachine($alerts),

            // The Recent Alerts list, newest first. Paged - see 'pagination'.
            'recent' => collect($page['data'])->map(function ($alert) {
                return [
                    'id'          => $alert->id,
                    'title'       => $alert->alert_sub_type,
                    'description' => $alert->description,
                    'severity'    => $alert->severity,
                    'type'        => $alert->alert_type,

                    'machine_id'  => $alert->equipment_name_id,
                    // Falls back to the chassis so the column an operator
                    // identifies the machine by is never blank.
                    'dumper_no'   => optional($alert->machine)->equipment_name ?: $alert->chassis_number,
                    'chassis_number' => $alert->chassis_number,

                    'value'       => $alert->alert_value === null ? null : (float) $alert->alert_value,
                    'unit'        => $alert->alert_unit,

                    'alerted_at'  => optional($alert->alerted_at)->toDateTimeString(),
                    'latitude'    => $this->float($alert->latitude),
                    'longitude'   => $this->float($alert->longitude),
                ];
            })->values()->all(),

            // Describes 'recent' only, not the totals above.
            'pagination' => $page['pagination'],

            'from' => $range[0]->toDateString(),
            'to'   => $range[1]->toDateString(),

            // VECV only. Stated so a quiet panel is not read as a quiet fleet
            // when most of the fleet is not being watched for this at all.
            'basis'       => 'vecv',
            'basis_count' => $this->fleet($filters)->where('source', 'vecv')->count(),
            'fleet_count' => $this->fleet($filters)->count(),
        ];
    }

    /**
     * Counts per value of one attribute, largest first.
     *
     * @param  \Illuminate\Support\Collection  $alerts
     * @param  string  $attribute
     * @return array
     */
    protected function countBy(Collection $alerts, $attribute)
    {
        return $alerts->countBy($attribute)
            ->sortDesc()
            ->map(function ($count, $value) {
                return ['name' => $value, 'count' => $count];
            })
            ->values()
            ->all();
    }

    /**
     * Sub-type breakdown, carrying the parent type and severity so the UI does
     * not have to look either up.
     *
     * @param  \Illuminate\Support\Collection  $alerts
     * @return array
     */
    protected function alertSubTypeBreakdown(Collection $alerts)
    {
        return $alerts->groupBy('alert_sub_type_id')
            ->map(function ($group, $subTypeId) {
                $first = $group->first();

                return [
                    'sub_type_id' => $subTypeId,
                    'name'        => $first->alert_sub_type,
                    'type'        => $first->alert_type,
                    'severity'    => $first->severity,
                    'count'       => $group->count(),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * "Events by Dumper", worst first. VECV machines only - see
     * safetyEventsByMachine() for the whole-fleet version.
     *
     * @param  \Illuminate\Support\Collection  $alerts
     * @param  int|string|null  $limit  Null is the panel's short default,
     *                                  "all" or 0 every machine that raised an
     *                                  event in the window.
     * @return array
     */
    protected function alertsByMachine(Collection $alerts, $limit = null)
    {
        return $this->rankMachineRows($this->alertMachineRows($alerts), $limit);
    }

    /**
     * "Events by Dumper" across both vendors.
     *
     * Truck Connect machines are listed alongside the VECV ones so the panel's
     * View All is the fleet, not the half of it one vendor happens to cover.
     * They carry their harsh-event count like any other row - which is zero on
     * every reading so far, and a zero here is a real answer: the machine is
     * being watched and reported nothing. Absent from the list it would be
     * indistinguishable from a machine nobody is watching at all.
     *
     * Sorted by count, so those zero rows sit at the bottom and the short
     * default list is unchanged - it still shows the worst offenders.
     *
     * @param  \Illuminate\Support\Collection  $events  VECV driving-behaviour alerts
     * @param  \Illuminate\Support\Collection  $fleet   The matching fleet snapshot
     * @param  array  $range
     * @param  array  $filters
     * @param  int|string|null  $limit
     * @return array
     */
    protected function safetyEventsByMachine(Collection $events, Collection $fleet, array $range, array $filters, $limit = null)
    {
        $rows = $this->alertMachineRows($events)
            ->concat($this->truckConnectMachineRows($range, $filters));

        return $this->rankMachineRows($this->withQuietMachines($rows, $fleet), $limit);
    }

    /**
     * Add the machines that reported but raised nothing, at zero.
     *
     * The VECV rows are built from the alert log, so a machine that behaved
     * itself has no row there at all - while the Truck Connect rows come from
     * the readings and are present at zero. Left alone the list would hide
     * exactly the well-behaved half of one vendor's fleet and show the other's,
     * which is not a fleet roster, just an artefact of where each row came
     * from.
     *
     * Only machines in the snapshot are added, so this stays a list of
     * machines that were actually reporting over the window.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @param  \Illuminate\Support\Collection  $fleet
     * @return \Illuminate\Support\Collection
     */
    protected function withQuietMachines(Collection $rows, Collection $fleet)
    {
        $listed = $rows->pluck('chassis_number')
            ->map(function ($chassis) {
                return strtoupper($chassis);
            })
            ->all();

        $quiet = $fleet
            ->reject(function ($row) use ($listed) {
                return in_array(strtoupper($row['chassis_number']), $listed, true);
            })
            ->map(function ($row) {
                return [
                    'machine_id'     => $row['machine_id'],
                    'chassis_number' => $row['chassis_number'],
                    'dumper_no'      => $row['dumper_no'] ?: $row['chassis_number'],
                    'count'          => 0,
                    'critical'       => 0,
                    'warning'        => 0,
                    'source'         => $row['source'],
                ];
            });

        return $rows->concat($quiet);
    }

    /**
     * One row per machine that raised a VECV alert in the set.
     *
     * @param  \Illuminate\Support\Collection  $alerts
     * @return \Illuminate\Support\Collection
     */
    protected function alertMachineRows(Collection $alerts)
    {
        return $alerts->groupBy('chassis_number')
            ->map(function ($group, $chassis) {
                $first = $group->first();

                return [
                    'machine_id'     => $first->equipment_name_id,
                    'chassis_number' => $chassis,
                    'dumper_no'      => optional($first->machine)->equipment_name ?: $chassis,
                    'count'          => $group->count(),
                    'critical'       => $group->where('severity', 'critical')->count(),
                    'warning'        => $group->where('severity', 'warning')->count(),
                    'source'         => 'vecv',
                ];
            })
            ->values();
    }

    /**
     * One row per Truck Connect machine reporting in the window, in the same
     * shape as the VECV rows so the two can be listed together.
     *
     * count is the harsh braking, acceleration and cornering flags summed -
     * the same figure truck_connect_harsh reports fleet-wide, split by machine.
     * critical and warning are zero rather than null because this feed
     * publishes no severity at all; there is nothing to grade.
     *
     * @param  array  $range
     * @param  array  $filters
     * @return \Illuminate\Support\Collection
     */
    protected function truckConnectMachineRows(array $range, array $filters)
    {
        return TruckConnectReading::query()
            ->leftJoin('equipment_names', function ($join) {
                $join->on('equipment_names.chassis_number', '=', 'truck_connect_readings.vin')
                    ->whereNotNull('equipment_names.chassis_number');
            })
            ->whereBetween('truck_connect_readings.reported_at', [$range[0], $range[1]])
            ->when(! empty($filters['machine_id']), function ($q) use ($filters) {
                $q->where('equipment_names.id', (int) $filters['machine_id']);
            })
            ->tap(function ($q) use ($filters) {
                $this->applyTruckConnectSearch($q, $filters);
            })
            // Grouped in the database rather than by pulling every reading:
            // this is one row per machine either way, and the window can hold
            // thousands of readings.
            ->groupBy(
                'truck_connect_readings.vin',
                'equipment_names.id',
                'equipment_names.equipment_name'
            )
            ->select([
                'truck_connect_readings.vin',
                'equipment_names.id as machine_id',
                'equipment_names.equipment_name as dumper_no',
                DB::raw('SUM(truck_connect_readings.harsh_braking) as harsh_braking'),
                DB::raw('SUM(truck_connect_readings.harsh_acceleration) as harsh_acceleration'),
                DB::raw('SUM(truck_connect_readings.harsh_cornering) as harsh_cornering'),
            ])
            ->get()
            ->map(function ($row) {
                return [
                    'machine_id'     => $row->machine_id === null ? null : (int) $row->machine_id,
                    'chassis_number' => $row->vin,
                    // Falls back to the VIN so the column an operator
                    // identifies the machine by is never blank.
                    'dumper_no'      => $row->dumper_no ?: $row->vin,
                    'count'          => (int) $row->harsh_braking
                                      + (int) $row->harsh_acceleration
                                      + (int) $row->harsh_cornering,
                    'critical'       => 0,
                    'warning'        => 0,
                    'source'         => 'truck_connect',
                ];
            });
    }

    /**
     * Order machine rows worst-first and cut them to the panel's length.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @param  int|string|null  $limit
     * @return array
     */
    protected function rankMachineRows(Collection $rows, $limit = null)
    {
        $limit = $this->panelLimit($limit, (int) config('vecv.alerts_by_machine_limit'));

        // Chassis is the tie-break so machines on the same count do not swap
        // places between refreshes - which matters most for the Truck Connect
        // rows, where every count is currently zero.
        $rows = $rows->sortBy([['count', 'desc'], ['chassis_number', 'asc']]);

        // View All: no slice rather than a very large one, so the list cannot
        // be quietly capped again as the fleet grows.
        if ($limit !== null) {
            $rows = $rows->take($limit);
        }

        return $rows->values()->all();
    }

    /**
     * Everything the fuel panel needs: the donut, the fleet average and the
     * vehicles running lowest.
     *
     * One entry point because the donut and the "lowest fuel" list are one
     * panel on screen and must never disagree. Served separately they would be
     * computed from two snapshots taken moments apart, and a vehicle could sit
     * in the Critical slice of one while the other still called it Low.
     *
     * @return array
     */
    public function fuelStatus(array $filters = [])
    {
        return $this->fuel(
            $this->fleet($filters),
            isset($filters['limit']) ? $filters['limit'] : null
        );
    }

    /**
     * The vehicles running lowest on fuel.
     *
     * @param  int|string|null  $limit
     * @return array
     */
    public function lowestFuel($limit = null, array $filters = [])
    {
        return $this->lowestFrom($this->fleet($filters), $limit);
    }

    /**
     * The lowest-fuel rows out of an already-loaded fleet snapshot.
     *
     * Takes the snapshot rather than fetching its own so the fuel panel builds
     * its donut and its list from the same rows in a single query.
     *
     * @param  \Illuminate\Support\Collection  $fleet
     * @param  int|string|null  $limit
     * @return array
     */
    protected function lowestFrom(Collection $fleet, $limit = null)
    {
        $limit = $this->panelLimit($limit, (int) config('vecv.lowest_fuel_limit'));

        // Reuses the donut's validity rule - present and inside 0-100 - so a
        // vehicle can never appear in the list under a bucket the donut did
        // not count it in. A missing or impossible reading is not "low on
        // fuel", it is unknown; left in, nulls sort to the top of an ascending
        // list and fill the panel with vehicles that have no data at all.
        $rows = $this->withValidFuel($fleet)
            // Chassis is the tie-break so two vehicles on the same level do
            // not swap places between refreshes.
            ->sortBy([
                ['fuel_level_pct', 'asc'],
                ['chassis_number', 'asc'],
            ]);

        // View All: no slice at all rather than a very large one, so the list
        // cannot be quietly capped again as the fleet grows.
        if ($limit !== null) {
            $rows = $rows->take($limit);
        }

        return $rows->values()->all();
    }

    /**
     * The search box's term, or '' when nothing was typed.
     *
     * @param  array  $filters
     * @return string
     */
    protected function searchTerm(array $filters)
    {
        return isset($filters['search']) ? trim((string) $filters['search']) : '';
    }

    /**
     * The term as a LIKE pattern, with the wildcards escaped.
     *
     * The fleet-side search matches with stripos, which treats % and _ as
     * ordinary characters. Escaping them here keeps one search box meaning one
     * thing across the response - unescaped, a term holding % would narrow the
     * vehicle list and widen the alert list in the same request.
     *
     * @param  string  $search
     * @return string
     */
    protected function likePattern($search)
    {
        return '%' . addcslashes($search, '%_\\') . '%';
    }

    /**
     * Narrow an alert query to the search term - chassis or machine name.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $filters
     * @return void
     */
    protected function applyAlertSearch($query, array $filters)
    {
        $search = $this->searchTerm($filters);

        if ($search === '') {
            return;
        }

        $pattern = $this->likePattern($search);

        $query->where(function ($q) use ($pattern) {
            $q->where('chassis_number', 'like', $pattern)
                ->orWhereHas('machine', function ($m) use ($pattern) {
                    $m->where('equipment_name', 'like', $pattern);
                });
        });
    }

    /**
     * The same for a Truck Connect query, which is joined to the vehicle
     * master rather than related to it - VIN or machine name.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $filters
     * @return void
     */
    protected function applyTruckConnectSearch($query, array $filters)
    {
        $search = $this->searchTerm($filters);

        if ($search === '') {
            return;
        }

        $pattern = $this->likePattern($search);

        $query->where(function ($q) use ($pattern) {
            $q->where('truck_connect_readings.vin', 'like', $pattern)
                ->orWhere('equipment_names.equipment_name', 'like', $pattern);
        });
    }

    /**
     * How many rows one of the dashboard's short "Top N" lists should return.
     *
     * Shared by every such list so View All means the same thing everywhere.
     * Absent is the panel's own default, so a first paint is unchanged. "all",
     * or any number below one, is a View All link asking for the lot; null
     * carries that back to the caller, which then takes no slice at all.
     *
     * @param  int|string|null  $limit
     * @param  int  $default
     * @return int|null
     */
    protected function panelLimit($limit, $default)
    {
        if ($limit === null || $limit === '') {
            return (int) $default;
        }

        if (is_string($limit) && strtolower(trim($limit)) === 'all') {
            return null;
        }

        return (int) $limit < 1 ? null : (int) $limit;
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
    protected function fleet(array $filters = [])
    {
        $asOf  = $this->asOf($filters);
        $range = $this->range($filters);

        $rows = $this->vecvFleet($asOf, $range)->concat($this->truckConnectFleet($asOf, $range));

        // A machine can in principle be fitted with both vendors' units. Key on
        // the chassis and keep whichever reading is newer, so it is counted
        // once and shown at its freshest rather than appearing twice in the
        // fleet total.
        return $rows
            ->groupBy(function ($row) {
                return strtoupper($row['chassis_number']);
            })
            ->map(function ($group) {
                return $group->sortByDesc(function ($row) {
                    // A row with no resolvable timestamp loses to one that has
                    // any timestamp at all.
                    return $row['last_reported_at'] ?: '';
                })->first();
            })
            ->values()
            ->pipe(function ($rows) use ($filters) {
                return $this->applyFilters($rows, $filters);
            });
    }

    /**
     * The instant staleness is measured against.
     *
     * "Now" when the window ends today, or when there is no window at all. For
     * a window that ended in the past it is the end of that window, because
     * measuring a historical reading against the present moment would mark
     * every machine offline and make the view useless - the question being
     * asked is "who was reporting then", not "who is reporting now".
     *
     * @param  array  $filters
     * @return \Carbon\Carbon
     */
    protected function asOf(array $filters)
    {
        $range = $this->range($filters);

        if ($range === null || $range[1]->isToday()) {
            return Carbon::now();
        }

        return $range[1]->copy();
    }

    /**
     * The requested window as [start, end], or null for "current state".
     *
     * Accepts either a range - from/to, which is what the date picker's Today,
     * Yesterday, Last 7 Days and Custom Range all reduce to - or a single date
     * as shorthand for a one-day window. A named "range" preset (see
     * presetRange()) is a third option, for a caller that would rather send a
     * key than compute dates itself; it only fills in what from/to/date left
     * empty, so explicit dates always win over a preset sent alongside them.
     *
     * Both ends are inclusive: "to" is stretched to the end of its day, so a
     * range ending today includes everything reported so far today rather than
     * stopping at midnight this morning.
     *
     * @param  array  $filters
     * @return array|null  [\Carbon\Carbon, \Carbon\Carbon]
     */
    protected function range(array $filters)
    {
        $from = $filters['from'] ?? $filters['date'] ?? null;
        $to   = $filters['to']   ?? $filters['date'] ?? null;

        if ((empty($from) || empty($to)) && ! empty($filters['range'])) {
            $preset = $this->presetRange($filters['range']);

            // An unrecognised key falls through to the "one end alone"
            // handling below rather than erroring - same reasoning as an
            // unparseable date: fall back to the live view, not a 500.
            if ($preset !== null) {
                return $preset;
            }
        }

        // One end alone is ambiguous - "everything since Monday" and
        // "everything up to Monday" are different questions and the UI sends
        // neither - so it falls back to the live view rather than guessing.
        if (empty($from) || empty($to)) {
            return null;
        }

        try {
            $start = Carbon::parse($from)->startOfDay();
            $end   = Carbon::parse($to)->endOfDay();
        } catch (\Throwable $e) {
            // An unparseable date falls back to the live view rather than
            // returning an empty fleet that reads as a data problem.
            return null;
        }

        // Reversed by mistake: read it the way it was plainly meant rather
        // than returning nothing.
        if ($start->greaterThan($end)) {
            return [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }

    /**
     * A named date-range preset as [start, end], both already stretched to
     * the edges of their day - or null when the key isn't one of these.
     *
     * The same reduction the date picker's buttons do client-side, done here
     * so a caller can send the key instead of computing dates. "Last N days"
     * counts today as one of the N, matching what the picker's own buttons
     * mean by the label.
     *
     * @param  string  $key
     * @return array|null  [\Carbon\Carbon, \Carbon\Carbon]
     */
    protected function presetRange($key)
    {
        $today = Carbon::today();
        $key   = strtolower(trim($key));

        switch ($key) {
            case 'today':
                return [$today->copy(), $today->copy()->endOfDay()];

            case 'yesterday':
                $yesterday = $today->copy()->subDay();
                return [$yesterday, $yesterday->copy()->endOfDay()];

            case 'last_7_days':
                return [$today->copy()->subDays(6), $today->copy()->endOfDay()];

            case 'last_30_days':
                return [$today->copy()->subDays(29), $today->copy()->endOfDay()];

            case 'this_month':
                return [$today->copy()->startOfMonth(), $today->copy()->endOfDay()];

            case 'last_month':
                $lastMonth = $today->copy()->subMonthNoOverflow();
                return [$lastMonth->copy()->startOfMonth(), $lastMonth->copy()->endOfMonth()];

            default:
                return null;
        }
    }

    /**
     * Current state of every VECV machine.
     *
     * Reads the fuel feed only: it is a superset of the location feed for
     * everything shown here - fuel, position, speed, odometer, engine hours and
     * status in one row - so using it alone avoids merging two feeds whose
     * readings were taken a minute apart and disagree slightly.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function vecvFleet(Carbon $asOf, $range = null)
    {
        return EquipmentFuelReading::latestPerChassis($range[0] ?? null, $range[1] ?? null)
            // Left join, not a constraint: the feed is the source of truth for
            // which machines exist. A chassis missing from the machine master
            // still appears here, with a null dumper number, rather than
            // vanishing from the fleet count.
            //
            // Joined live rather than reading the stored equipment_name_id, so
            // registering a machine fixes the whole dashboard immediately
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
            ->map(function ($reading) use ($asOf) {
                return $this->presentVecvRow($reading, $asOf);
            });
    }

    /**
     * Current state of every Truck Connect machine.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function truckConnectFleet(Carbon $asOf, $range = null)
    {
        return TruckConnectReading::latestPerVin($range[0] ?? null, $range[1] ?? null)
            ->leftJoin('equipment_names', function ($join) {
                $join->on('equipment_names.chassis_number', '=', 'truck_connect_readings.vin')
                    ->whereNotNull('equipment_names.chassis_number');
            })
            ->select(
                'truck_connect_readings.*',
                'equipment_names.id as machine_id',
                'equipment_names.equipment_name'
            )
            ->get()
            ->map(function ($reading) use ($asOf) {
                return $this->presentTruckConnectRow($reading, $asOf);
            });
    }

    /**
     * Shape one VECV reading.
     *
     * Live values are blanked on a stale reading rather than shown as-is. The
     * feed keeps returning the last known speed and status indefinitely, so
     * displaying them would state that a machine parked since Sunday is doing
     * 12 km/h. Fuel, odometer and engine hours survive staleness - they are the
     * last known true values and do not drift while a machine sits - so they
     * are kept, with age exposed so the UI can qualify them.
     *
     * @param  \App\Models\EquipmentFuelReading  $reading
     * @return array
     */
    protected function presentVecvRow(EquipmentFuelReading $reading, Carbon $asOf)
    {
        [$age, $isStale] = $this->freshness(
            $reading->reported_at,
            (int) config('vecv.stale_after_minutes'),
            $asOf
        );

        $speed   = $this->speed($reading->vehicle_speed);
        $fuelPct = $reading->fuel_level_pct === null ? null : (float) $reading->fuel_level_pct;

        return $this->row([
            'source'         => 'vecv',
            'chassis_number' => $reading->chassis_number,
            'machine_id'     => $reading->machine_id,
            'machine_name'   => $reading->equipment_name,

            'is_stale'       => $isStale,
            'age_minutes'    => $age,
            'last_reported_at' => $reading->reported_at ? $reading->reported_at->toDateTimeString() : null,

            // VECV vocabulary: MOVING / IDLING / STOPPED.
            'vehicle_status' => $reading->vehicle_status,
            'vehicle_speed'  => $speed,

            // No ignition flag anywhere on this feed, so IDLING and STOPPED
            // stand in for it - see state().
            'ignition'       => null,
            'engine_rpm'     => null,

            'fuel_level_pct' => $fuelPct,
            'fuel_level_ltr' => $this->float($reading->fuel_level_ltr),
            'adblue_pct'     => null,
            'def_level_ltr'  => $this->float($reading->def_level_ltr),

            // Whole kilometres on this feed.
            'odometer_km'    => $this->float($reading->odometer),

            // Not on the Truck Connect feed - VECV's advantage. Utilisation
            // reporting will want this.
            'engine_hours'   => $this->float($reading->engine_operating_hours),

            'latitude'       => $this->float($reading->latitude),
            'longitude'      => $this->float($reading->longitude),
        ]);
    }

    /**
     * Shape one Truck Connect reading into the same row as a VECV one.
     *
     * @param  \App\Models\TruckConnectReading  $reading
     * @return array
     */
    protected function presentTruckConnectRow(TruckConnectReading $reading, Carbon $asOf)
    {
        [$age, $isStale] = $this->freshness(
            $reading->reported_at,
            (int) config('truckconnect.stale_after_minutes'),
            $asOf
        );

        $speed = $this->speed($reading->vehicle_speed);

        return $this->row([
            'source'         => 'truck_connect',
            'chassis_number' => $reading->vin,
            'machine_id'     => $reading->machine_id,
            'machine_name'   => $reading->equipment_name,

            'is_stale'       => $isStale,
            'age_minutes'    => $age,
            'last_reported_at' => $reading->reported_at ? $reading->reported_at->toDateTimeString() : null,

            // Its own vocabulary: RUNNING / IDLE / OFFLINE, which measurement
            // shows means the same three things as VECV's MOVING / IDLING /
            // STOPPED. Not used to decide connectivity: "OFFLINE" here means
            // the engine is off, not that the unit has stopped reporting.
            'vehicle_status' => $reading->vehicle_status,
            'vehicle_speed'  => $speed,

            // A real ignition flag, which VECV never publishes. state() prefers
            // it, so these rows classify by the rule as written rather than by
            // a status word standing in for it.
            'ignition'       => $reading->ignition,
            'engine_rpm'     => $this->float($reading->engine_rpm),

            // Interpreted from the raw vendor value against config - null while
            // a unit is unconfirmed rather than a guess.
            'fuel_level_pct' => $reading->fuel_level_pct,
            'fuel_level_ltr' => null,
            'adblue_pct'     => $reading->adblue_level_pct,
            'def_level_ltr'  => null,

            'odometer_km'    => $reading->odometer_km,

            // Not published on this feed.
            'engine_hours'   => null,

            'latitude'       => $this->float($reading->latitude),
            'longitude'      => $this->float($reading->longitude),
        ]);
    }

    /**
     * Age in minutes and whether that counts as stale, against a reference
     * instant.
     *
     * Computed here rather than read off the model's is_stale accessor, because
     * that accessor always measures against "now". When the dashboard is
     * pointed at a past date the reference is the end of that day instead, and
     * a model that only knows about now would report every historical machine
     * as offline.
     *
     * Each vendor keeps its own threshold, so the two feeds can be judged by
     * how often they actually report rather than by one shared number.
     *
     * @param  \Carbon\Carbon|null  $reportedAt
     * @param  int  $thresholdMinutes
     * @param  \Carbon\Carbon  $asOf
     * @return array  [age|null, isStale]
     */
    protected function freshness($reportedAt, $thresholdMinutes, Carbon $asOf)
    {
        if (! $reportedAt) {
            // No usable timestamp counts as stale: showing an unverifiable
            // reading as current is the failure worth avoiding.
            return [null, true];
        }

        $age = $reportedAt->diffInMinutes($asOf);

        return [$age, $age > $thresholdMinutes];
    }

    /**
     * Narrow a fleet to what the dashboard filters asked for.
     *
     * Applied after the rows are built, because connectivity, operational state
     * and fuel bucket are all derived - none of them is a column to put in a
     * WHERE clause.
     *
     * Every panel takes the same filters, so a filtered dashboard is internally
     * consistent: filtering to Critical fuel makes the donut read 100%
     * critical, which is the honest answer to "show me only the critical ones"
     * rather than a mixed view that contradicts the table beneath it.
     *
     * @param  \Illuminate\Support\Collection  $rows
     * @param  array  $filters
     * @return \Illuminate\Support\Collection
     */
    protected function applyFilters(Collection $rows, array $filters)
    {
        // The dumper dropdown. Machine id, not chassis - the same value the
        // machine list endpoint returns.
        if (! empty($filters['machine_id'])) {
            $rows = $rows->where('machine_id', (int) $filters['machine_id']);
        }

        if (! empty($filters['connectivity']) && in_array($filters['connectivity'], ['online', 'offline'], true)) {
            $rows = $rows->where('connectivity', $filters['connectivity']);
        }

        if (! empty($filters['operational_status'])) {
            $rows = $rows->where('operational_state', $filters['operational_status']);
        }

        if (! empty($filters['fuel_status'])) {
            $rows = $rows->where('fuel_status', $filters['fuel_status']);
        }

        if (! empty($filters['source'])) {
            $rows = $rows->where('source', $filters['source']);
        }

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';

        if ($search !== '') {
            $rows = $rows->filter(function ($row) use ($search) {
                return stripos($row['chassis_number'], $search) !== false
                    || ($row['dumper_no'] !== null && stripos($row['dumper_no'], $search) !== false);
            });
        }

        return $rows->values();
    }

    /**
     * Assemble a fleet row from the fields a feed could supply.
     *
     * Both vendors go through here so every row carries the same keys in the
     * same shape. A field one feed does not publish is present and null rather
     * than absent, so the front end renders a dash instead of silently dropping
     * a column and looking complete.
     *
     * @param  array  $data
     * @return array
     */
    protected function row(array $data)
    {
        $isStale = (bool) $data['is_stale'];

        return [
            'source'         => $data['source'],
            'chassis_number' => $data['chassis_number'],
            'machine_id'     => $data['machine_id'],

            // Falls back to the chassis so the table is never blank in the
            // column an operator identifies the machine by. Register the
            // chassis on equipment_names to replace it with the machine name.
            'dumper_no'      => $data['machine_name'],
            'display_name'   => $data['machine_name'] ?: $data['chassis_number'],

            'connectivity'   => $isStale ? 'offline' : 'online',
            'is_stale'       => $isStale,
            'age_minutes'    => $data['age_minutes'],
            'last_reported_at' => $data['last_reported_at'],

            // The vendor's own status word, passed through unchanged and NOT
            // used to decide connectivity above.
            //
            // Named "vendor_" because Truck Connect's value reads as a
            // contradiction otherwise: "OFFLINE" beside connectivity "online".
            // It is not about the connection. Measured across fresh readings on
            // 2026-09-03 it tracks the ignition flag exactly - RUNNING and IDLE
            // on every ignition-on machine, OFFLINE on every ignition-off one -
            // so it describes the machine's power state, and the two feeds turn
            // out to mean the same three things:
            //
            //     RUNNING ~ MOVING    (ignition on, travelling)
            //     IDLE    ~ IDLING    (ignition on, stopped)
            //     OFFLINE ~ STOPPED   (ignition off)
            //
            // Do not read it as connectivity. A machine parked with its engine
            // off reports "OFFLINE" while sending fuel, odometer and GPS every
            // few minutes; treating that as "no data" would drop it from the
            // fleet counts and out of the low-fuel list.
            'vendor_status'  => $isStale ? null : $data['vehicle_status'],

            // Suppressed when stale: the feeds keep returning the last known
            // speed and status forever, so showing them would report a machine
            // parked since Sunday as doing 12 km/h.
            'vehicle_speed'  => $isStale ? null : $data['vehicle_speed'],
            'engine_rpm'     => $isStale ? null : $data['engine_rpm'],
            'ignition'       => $isStale ? null : $data['ignition'],

            // Single classification used by the tiles, the operational split
            // and the table badge, so they cannot disagree.
            //
            // A stale row is 'offline' rather than null: not knowing what a
            // machine is doing is itself a state worth counting, and it is the
            // one an operator acts on.
            'operational_state' => $isStale
                ? 'offline'
                : $this->state($data['vehicle_speed'], $data['vehicle_status'], $data['ignition']),

            // These survive staleness - a parked machine's tank level and
            // odometer are still its real ones - so they are kept, with
            // age_minutes exposed so the UI can qualify them.
            'fuel_level_pct' => $data['fuel_level_pct'],
            'fuel_level_ltr' => $data['fuel_level_ltr'],
            'fuel_status'    => $this->bucket($data['fuel_level_pct']),
            'adblue_pct'     => $data['adblue_pct'],
            'def_level_ltr'  => $data['def_level_ltr'],

            'odometer_km'    => $data['odometer_km'],
            'engine_hours'   => $data['engine_hours'],

            'latitude'       => $data['latitude'],
            'longitude'      => $data['longitude'],
        ];
    }

    /**
     * The operational split across the whole fleet.
     *
     * Classification lives in state() and presentRow(); this only tallies it.
     *
     * @param  \Illuminate\Support\Collection  $fleet
     * @return array
     */
    protected function operational(Collection $fleet)
    {
        $counts = $this->stateCounts($fleet);

        return [
            'counts'      => $counts,
            'percentages' => $this->distribute($counts, $fleet->count()),

            // Denominator is the whole fleet, and "offline" is one of the
            // categories rather than an exclusion. That is what makes the bars
            // add up to the fleet size and the percentages to 100 - counting
            // only the online vehicles leaves a reader wondering where the
            // rest went.
            'basis'       => 'all vehicles',
            'basis_count' => $fleet->count(),
        ];
    }

    /**
     * The fuel donut and fleet average.
     *
     * @param  \Illuminate\Support\Collection  $fleet
     * @param  int|string|null  $lowestLimit  Rows for the "lowest fuel" list;
     *                                        null is the default, "all" or 0
     *                                        every vehicle. Sizes that list
     *                                        only - the donut and the average
     *                                        always cover the whole fleet.
     * @return array
     */
    protected function fuel(Collection $fleet, $lowestLimit = null)
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

            // Summing floats leaves a binary-representation tail -
            // 1281.7700000000002 for readings that are each exact to the paisa.
            // Two decimals because that is the precision the readings are
            // stored at (decimal(10,2)); rounding harder would throw away a
            // digit the source actually has.
            'total_litres' => round($fleet->sum(function ($row) {
                return $row['fuel_level_ltr'] ?: 0;
            }), 2) ?: null,

            'basis'       => 'all vehicles, last known level',
            'basis_count' => $known->count(),

            // How many of those levels came from a reading older than the
            // staleness threshold, so the UI can footnote the average.
            'stale_readings' => $known->where('is_stale', true)->count(),

            'thresholds' => [
                'normal_min'   => (float) config('vecv.fuel_buckets.normal_min'),
                'critical_max' => (float) config('vecv.fuel_buckets.critical_max'),
            ],

            // The "lowest fuel" panel, from the same snapshot and the same
            // bucketing as the donut above. Short by default; View All asks
            // for every vehicle counted in 'basis_count'.
            'lowest' => $this->lowestFrom($fleet, $lowestLimit),
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
     * Steps 3 and 4 key on the ignition flag where there is one. Truck Connect
     * publishes it, so those rows classify by the rule exactly as written. VECV
     * publishes no ignition field on any endpoint, so IDLING and STOPPED stand
     * in for it - they carry precisely that meaning in its vocabulary, engine
     * running but not travelling, versus shut down.
     *
     * Anything unrecognised falls to unknown rather than engine_off, honouring
     * the rule that a missing ignition reading must never be reported as
     * "switched off".
     *
     * @param  float|null   $speed     Already validated by speed()
     * @param  string|null  $status
     * @param  bool|null    $ignition  Null on feeds that do not publish it
     * @return string
     */
    protected function state($speed, $status, $ignition = null)
    {
        if ($speed === null) {
            return 'unknown';
        }

        if ($speed > 0) {
            return 'moving';
        }

        // Preferred when present: it is the actual signal the definition asks
        // for, rather than a status word standing in for it.
        if ($ignition !== null) {
            return $ignition ? 'stationary' : 'engine_off';
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
        $counts = [
            'moving'     => 0,
            'stationary' => 0,
            'engine_off' => 0,
            'offline'    => 0,
            'unknown'    => 0,
        ];

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
