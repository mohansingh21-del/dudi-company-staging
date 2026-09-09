<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single position snapshot pulled from the VECV rFMS Location API.
 *
 * Readings are immutable point-in-time facts - they are inserted and never
 * updated. Distance covered is derived by differencing odometer between two
 * readings. Engine hours are not on this feed; use EquipmentFuelReading for
 * utilisation.
 */
class EquipmentLocationReading extends Model
{
    use HasFactory;

    protected $table = 'equipment_location_readings';

    protected $fillable = [
        'chassis_number',
        'equipment_name_id',
        'reg_no',
        'vehicle_status',
        'latitude',
        'longitude',
        'vehicle_speed',
        'odometer',
        'vehicle_direction',
        'device_id',
        'reported_at',
        'raw',
    ];

    protected $casts = [
        'equipment_name_id' => 'integer',
        'latitude'          => 'decimal:7',
        'longitude'         => 'decimal:7',
        'vehicle_speed'     => 'decimal:2',
        'odometer'          => 'decimal:2',
        'vehicle_direction' => 'decimal:2',
        'reported_at'       => 'datetime',
        'raw'               => 'array',
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
    | 31 hours is still returned at its last known position. Age has to be
    | derived from the reported timestamp, so it is exposed on every reading.
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
     * Only readings recent enough to represent the current position of a machine.
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
     * Only readings that actually carry a fix.
     *
     * A vehicle with no data comes back as an object of nulls rather than
     * being omitted, so a position must never be assumed present.
     */
    public function scopeLocated($query)
    {
        return $query->whereNotNull('latitude')->whereNotNull('longitude');
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

    /**
     * Only readings where the machine was under way.
     *
     * IDLING and STOPPED both report a position; only MOVING implies travel.
     */
    public function scopeMoving($query)
    {
        return $query->where('vehicle_status', 'MOVING');
    }

    /*
    |--------------------------------------------------------------------------
    | Lookups
    |--------------------------------------------------------------------------
    */

    /**
     * The reading closest to a given instant for one machine, within a window.
     *
     * Used to resolve shift-boundary odometer values. Precision is bounded by
     * the poll interval - a reading can never be fresher than the last sync.
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
}
