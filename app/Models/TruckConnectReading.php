<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single telemetry snapshot from the Truck Connect API.
 *
 * Readings are immutable point-in-time facts - inserted, never updated.
 *
 * Three vendor values arrive with an ambiguous unit or timezone. They are
 * stored verbatim in *_raw columns and interpreted here, at read time, against
 * config/truckconnect.php. While a knob reads 'unknown' the matching accessor
 * returns null instead of guessing, so an unconfirmed value can never reach a
 * dashboard looking like a confirmed one. Answering a question later corrects
 * every row already stored, with no backfill and no re-fetch.
 */
class TruckConnectReading extends Model
{
    use HasFactory;

    protected $table = 'truck_connect_readings';

    protected $fillable = [
        'vin',
        'vehicle_id',
        'registration_id',
        'device_imei',
        'ignition',
        'vehicle_status',
        'message_status',
        'crt',
        'latitude',
        'longitude',
        'heading',
        'altitude',
        'vehicle_speed',
        'engine_rpm',
        'odometer_raw',
        'fuel_level_raw',
        'adblue_level_raw',
        'harsh_acceleration',
        'harsh_braking',
        'harsh_cornering',
        'gps_time_raw',
        'reported_at',
        'raw',
    ];

    protected $casts = [
        'vehicle_id'         => 'integer',
        'ignition'           => 'boolean',
        'latitude'           => 'decimal:7',
        'longitude'          => 'decimal:7',
        'heading'            => 'decimal:2',
        'altitude'           => 'decimal:2',
        'vehicle_speed'      => 'decimal:2',
        'engine_rpm'         => 'decimal:2',
        'odometer_raw'       => 'decimal:2',
        'fuel_level_raw'     => 'decimal:2',
        'adblue_level_raw'   => 'decimal:2',
        'harsh_acceleration' => 'integer',
        'harsh_braking'      => 'integer',
        'harsh_cornering'    => 'integer',
        'reported_at'        => 'datetime',
        'raw'                => 'array',
    ];

    protected $appends = [
        'odometer_km',
        'fuel_level_pct',
        'age_minutes',
        'is_stale',
        'is_online',
        'is_moving',
        'is_stationary',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The vehicle this reading belongs to, when the VIN has been matched.
     *
     * Linked to vehicles rather than equipment_names: the VIN corresponds to
     * vehicles.chassis_number, and the dumper number shown on screen is
     * vehicles.vehicle_number.
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Unconfirmed vendor semantics
    |--------------------------------------------------------------------------
    */

    /**
     * Odometer in kilometres, or null while the source unit is unconfirmed.
     *
     * @return float|null
     */
    public function getOdometerKmAttribute()
    {
        if ($this->odometer_raw === null) {
            return null;
        }

        switch (config('truckconnect.odometer_unit')) {
            case 'metres':
                return round((float) $this->odometer_raw / 1000, 2);
            case 'kilometres':
                return round((float) $this->odometer_raw, 2);
            default:
                return null;
        }
    }

    /**
     * Fuel level as a percentage, or null when it cannot be established.
     *
     * Null covers two different situations, and they are worth telling apart
     * when reading this: the unit is still unconfirmed, or the unit is known to
     * be litres. Litres cannot be turned into a percentage without a per-vehicle
     * tank capacity, which this system does not record - so if the answer comes
     * back 'litres', the fuel gauge, the Normal/Low/Critical buckets and the
     * fleet average all need a tank capacity column before they can be built.
     *
     * @return float|null
     */
    public function getFuelLevelPctAttribute()
    {
        if ($this->fuel_level_raw === null) {
            return null;
        }

        return config('truckconnect.fuel_level_unit') === 'percent'
            ? round((float) $this->fuel_level_raw, 2)
            : null;
    }

    /**
     * AdBlue/DEF level as a percentage, on the same terms as fuel.
     *
     * @return float|null
     */
    public function getAdblueLevelPctAttribute()
    {
        if ($this->adblue_level_raw === null) {
            return null;
        }

        return config('truckconnect.adblue_level_unit') === 'percent'
            ? round((float) $this->adblue_level_raw, 2)
            : null;
    }

    /**
     * Which vendor questions are still open.
     *
     * Intended for the "to be confirmed with Truck Connect" footnote: the note
     * disappears on its own once the config is answered, rather than being
     * hand-removed and forgotten.
     *
     * @return array
     */
    public static function pendingConfirmations()
    {
        $pending = [];

        if (! in_array(config('truckconnect.odometer_unit'), ['metres', 'kilometres'], true)) {
            $pending[] = 'odometer unit';
        }

        if (! in_array(config('truckconnect.fuel_level_unit'), ['percent', 'litres'], true)) {
            $pending[] = 'fuel level unit';
        }

        if (! in_array(config('truckconnect.adblue_level_unit'), ['percent', 'litres'], true)) {
            $pending[] = 'AdBlue level unit';
        }

        if (config('truckconnect.source_timezone') === 'unknown') {
            $pending[] = 'GPS timestamp timezone';
        }

        return $pending;
    }

    /*
    |--------------------------------------------------------------------------
    | Staleness
    |--------------------------------------------------------------------------
    |
    | The feed returns the last known reading whether or not the vehicle is
    | still reporting - the sample carries a live idle RPM on a vehicle marked
    | OFFLINE with the ignition off. Age has to be derived rather than trusted.
    |
    */

    /**
     * Minutes between the reading being reported and now.
     *
     * Null while the source timezone is unconfirmed, because reported_at has
     * not been resolved yet - not because the reading has no timestamp.
     *
     * @return int|null
     */
    public function getAgeMinutesAttribute()
    {
        if (! $this->reported_at) {
            return null;
        }

        return $this->reported_at->diffInMinutes(Carbon::now());
    }

    /**
     * Whether this reading is too old to be treated as live.
     *
     * An unresolvable age counts as stale: showing an unverifiable reading as
     * current is the failure worth avoiding here.
     *
     * @return bool
     */
    public function getIsStaleAttribute()
    {
        $age = $this->age_minutes;

        if ($age === null) {
            return true;
        }

        return $age > (int) config('truckconnect.stale_after_minutes');
    }

    /*
    |--------------------------------------------------------------------------
    | Operational state
    |--------------------------------------------------------------------------
    */

    /**
     * @return bool
     */
    public function getIsOnlineAttribute()
    {
        return strcasecmp((string) $this->vehicle_status, 'OFFLINE') !== 0;
    }

    /**
     * @return bool
     */
    public function getIsMovingAttribute()
    {
        return $this->vehicle_speed !== null && (float) $this->vehicle_speed > 0;
    }

    /**
     * Ignition on but not travelling - idling on site rather than shut down.
     *
     * @return bool
     */
    public function getIsStationaryAttribute()
    {
        return $this->ignition === true && ! $this->is_moving;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Only readings recent enough to represent the current state of a vehicle.
     *
     * Named "live" rather than "fresh" because Eloquent already defines a
     * fresh() method on Model, which would shadow the scope.
     */
    public function scopeLive($query)
    {
        $cutoff = Carbon::now()->subMinutes((int) config('truckconnect.stale_after_minutes'));

        return $query->where('reported_at', '>=', $cutoff);
    }

    public function scopeForVin($query, $vin)
    {
        return $query->where('vin', trim($vin));
    }

    public function scopeForVehicle($query, $vehicleId)
    {
        return $query->where('vehicle_id', $vehicleId);
    }

    /**
     * Only readings that actually carry a GPS fix.
     */
    public function scopeLocated($query)
    {
        return $query->whereNotNull('latitude')->whereNotNull('longitude');
    }

    /*
    |--------------------------------------------------------------------------
    | Lookups
    |--------------------------------------------------------------------------
    */

    /**
     * The most recently fetched reading for every VIN - the fleet's current
     * state, and the basis for the dashboard.
     *
     * Keyed on MAX(id) rather than MAX(reported_at) for two reasons: rows are
     * insert-only so the highest id is always the latest fetch, and reported_at
     * is null while the timezone question is open, which would leave this
     * returning nothing at the very moment it is needed.
     *
     * The column is table-qualified because callers join this against the
     * vehicle master, which also has an id - MySQL rejects the subquery as
     * ambiguous otherwise.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function latestPerVin()
    {
        return static::whereIn('truck_connect_readings.id', function ($query) {
            $query->selectRaw('MAX(id)')
                ->from('truck_connect_readings')
                ->groupBy('vin');
        });
    }
}
