<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One event from the VECV alert log.
 *
 * Alerts are immutable point-in-time facts - inserted, never updated - and
 * unlike the telemetry feeds they are already discrete events, so a count over
 * a window is a real count rather than something differenced out of snapshots.
 */
class VecvAlert extends Model
{
    use HasFactory;

    protected $table = 'vecv_alerts';

    protected $fillable = [
        'chassis_number',
        'equipment_name_id',
        'customer_id',
        'reg_no',
        'alert_type',
        'alert_sub_type_id',
        'alert_sub_type',
        'alert_value',
        'alert_unit',
        'alert_time_raw',
        'alerted_at',
        'latitude',
        'longitude',
        'raw',
    ];

    protected $casts = [
        'equipment_name_id' => 'integer',
        'alert_value'       => 'decimal:2',
        'latitude'          => 'decimal:7',
        'longitude'         => 'decimal:7',
        'alerted_at'        => 'datetime',
        'raw'               => 'array',
    ];

    /**
     * Severity by sub-type, for the Recent Alerts list.
     *
     * The feed carries no severity of its own, so this is our judgement, kept
     * in one place rather than spread across the UI. Anything unrecognised is
     * "info" rather than "critical": a new alert type the vendor adds must not
     * arrive already shouting.
     */
    const SEVERITIES = [
        'FUEL_DRAIN'           => 'critical',
        'LOW_FUEL'             => 'critical',
        'OVER_SPEEDING'        => 'warning',
        'HARSH_BRAKING'        => 'warning',
        'HARSH_ACCELERATION'   => 'warning',
        'HARSH_CORNERING'      => 'warning',
        'UNAUTHORIZED_USAGE'   => 'warning',
        'NON_STOP_DRIVING'     => 'warning',
        'OVER_STOPPAGE'        => 'info',
        'EXCESSIVE_IDLING'     => 'info',
        'FUEL_REFILL'          => 'info',
        'Device Disconnection' => 'warning',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function machine()
    {
        return $this->belongsTo(EquipmentName::class, 'equipment_name_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * @return string
     */
    public function getSeverityAttribute()
    {
        return self::SEVERITIES[$this->alert_sub_type_id] ?? 'info';
    }

    /**
     * A one-line description, the way the Recent Alerts panel reads.
     *
     * Built from value and unit where the alert carries them - a device
     * disconnection carries neither - so the sentence degrades to the alert
     * name rather than to "  ".
     *
     * @return string
     */
    public function getDescriptionAttribute()
    {
        if ($this->alert_value === null) {
            return $this->alert_sub_type;
        }

        // Trailing zeros off a decimal cast: "36.00 Km" reads worse than
        // "36 Km" for a speed.
        $value = rtrim(rtrim((string) $this->alert_value, '0'), '.');

        return trim($this->alert_sub_type . ' ' . $value . ' ' . (string) $this->alert_unit);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Alerts inside a window, both ends inclusive.
     */
    public function scopeBetweenDates($query, $from, $to)
    {
        return $query->whereBetween('alerted_at', [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ]);
    }

    public function scopeForMachine($query, $equipmentNameId)
    {
        return $query->where('equipment_name_id', $equipmentNameId);
    }

    public function scopeOfType($query, $alertType)
    {
        return $query->where('alert_type', $alertType);
    }
}
