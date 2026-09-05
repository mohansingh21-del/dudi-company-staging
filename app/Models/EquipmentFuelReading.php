<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single telematics snapshot pulled from the VECV rFMS Fuel API.
 *
 * Readings are immutable point-in-time facts - they are inserted and never
 * updated. Consumption is derived by differencing lifetime_fuel_consumed
 * between two readings, not by differencing tank level.
 */
class EquipmentFuelReading extends Model
{
    use HasFactory;

    protected $table = 'equipment_fuel_readings';

    protected $fillable = [
        'chassis_number',
        'equipment_name_id',
        'reg_no',
        'fuel_type',
        'vehicle_status',
        'latitude',
        'longitude',
        'vehicle_speed',
        'odometer',
        'engine_operating_hours',
        'fuel_level_pct',
        'fuel_level_ltr',
        'def_level_ltr',
        'lifetime_fuel_consumed',
        'soc_level',
        'battery_temperature',
        'co2_saving',
        'reported_at',
        'raw',
    ];

    protected $casts = [
        'equipment_name_id'      => 'integer',
        'latitude'               => 'decimal:7',
        'longitude'              => 'decimal:7',
        'vehicle_speed'          => 'decimal:2',
        'odometer'               => 'decimal:2',
        'engine_operating_hours' => 'decimal:2',
        'fuel_level_pct'         => 'decimal:2',
        'fuel_level_ltr'         => 'decimal:2',
        'def_level_ltr'          => 'decimal:2',
        'lifetime_fuel_consumed' => 'decimal:2',
        'soc_level'              => 'decimal:2',
        'battery_temperature'    => 'decimal:2',
        'co2_saving'             => 'decimal:2',
        'reported_at'            => 'datetime',
        'raw'                    => 'array',
    ];

    protected $appends = [
        'age_minutes',
        'is_stale',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The machine this reading belongs to, when the chassis has been matched.
     */
    public function machine()
    {
        return $this->belongsTo(EquipmentName::class, 'equipment_name_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Staleness
    |--------------------------------------------------------------------------
    |
    | The API carries no staleness marker: a vehicle that has not reported for
    | 31 hours is still returned as "MOVING" at speed. Age has to be derived
    | from the reported timestamp, so it is exposed on every reading.
    |
    */

    /**
     * Minutes between the reading being reported and now.
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
     * @return bool
     */
    public function getIsStaleAttribute()
    {
        $age = $this->age_minutes;

        if ($age === null) {
            return true;
        }

        return $age > (int) config('vecv.stale_after_minutes');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Only readings recent enough to represent the current state of a machine.
     *
     * Named "live" rather than "fresh" because Eloquent already defines a
     * fresh() method on Model, which would shadow the scope.
     */
    public function scopeLive($query)
    {
        $cutoff = Carbon::now()->subMinutes((int) config('vecv.stale_after_minutes'));

        return $query->where('reported_at', '>=', $cutoff);
    }

    public function scopeForChassis($query, $chassisNumber)
    {
        return $query->where('chassis_number', trim($chassisNumber));
    }

    public function scopeForMachine($query, $equipmentNameId)
    {
        return $query->where('equipment_name_id', $equipmentNameId);
    }

    /**
     * Readings between two instants, oldest first.
     */
    public function scopeBetween($query, $from, $to)
    {
        return $query->where('reported_at', '>=', $from)
            ->where('reported_at', '<=', $to)
            ->orderBy('reported_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Lookups
    |--------------------------------------------------------------------------
    */

    /**
     * The reading closest to a given instant for one machine, within a window.
     *
     * Used to resolve shift-boundary values. Precision is bounded by the poll
     * interval - a reading can never be fresher than the last sync.
     *
     * @param  int  $equipmentNameId
     * @param  \Carbon\Carbon  $at
     * @param  int  $windowMinutes
     * @return static|null
     */
    public static function nearest($equipmentNameId, Carbon $at, $windowMinutes = 60)
    {
        return static::where('equipment_name_id', $equipmentNameId)
            ->whereBetween('reported_at', [
                $at->copy()->subMinutes($windowMinutes),
                $at->copy()->addMinutes($windowMinutes),
            ])
            ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, reported_at, ?))', [$at->toDateTimeString()])
            ->first();
    }

    /**
     * The most recently fetched reading for every chassis - the fleet's current
     * state, and the basis for the telematics dashboard.
     *
     * Keyed on MAX(id) rather than MAX(reported_at) because rows are
     * insert-only, so the highest id is always the latest fetch, and it stays
     * correct even for a vehicle whose clock or feed briefly runs backwards.
     *
     * The column is table-qualified because callers join this against the
     * vehicle master, which also has an id - MySQL rejects the subquery as
     * ambiguous otherwise.
     *
     * Passing a window narrows it to the last reading inside that window, which
     * is what a dashboard viewing a past date or range needs - the latest
     * reading overall would show today's state under last week's heading.
     *
     * Both ends are required together; either alone is ignored.
     *
     * @param  \Carbon\Carbon|string|null  $from  Inclusive start
     * @param  \Carbon\Carbon|string|null  $to    Inclusive end
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function latestPerChassis($from = null, $to = null)
    {
        return static::whereIn('equipment_fuel_readings.id', function ($query) use ($from, $to) {
            $query->selectRaw('MAX(id)')
                ->from('equipment_fuel_readings')
                ->when($from && $to, function ($q) use ($from, $to) {
                    // The last reading inside the window, not the last overall.
                    $q->whereBetween('reported_at', [$from, $to]);
                })
                ->groupBy('chassis_number');
        });
    }
}
