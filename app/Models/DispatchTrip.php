<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class DispatchTrip extends Model
{
    use HasFactory;

    protected $table = 'dispatch_trips';

    protected $fillable = [
        'trip_reference_no',
        'shift_plan_id',
        'shift_id',
        'site_id',
        'dumper_equipment_id',
        'driver_id',
        'excavator_equipment_id',
        'loading_point_id',
        'dumping_point_id',
        'trip_date_time',
        'start_time',
        'end_time',
        'cycle_time_minutes',
        'quantity_bcm',
        'distance_meters',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'trip_date_time' => 'datetime',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'cycle_time_minutes' => 'decimal:2',
        'quantity_bcm' => 'decimal:2',
        'distance_meters' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | Static Helper
    |--------------------------------------------------------------------------
    */

    /**
     * Helper to calculate cycle time in minutes between two datetimes.
     *
     * @param string|Carbon $start
     * @param string|Carbon $end
     * @return float
     */
    public static function calculateCycleTime($start, $end)
    {
        $startCarbon = $start instanceof Carbon ? $start : Carbon::parse($start);
        $endCarbon = $end instanceof Carbon ? $end : Carbon::parse($end);

        $diffInSeconds = $endCarbon->diffInSeconds($startCarbon);
        return round($diffInSeconds / 60, 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function shiftPlan()
    {
        return $this->belongsTo(ShiftPlan::class, 'shift_plan_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function dumper()
    {
        return $this->belongsTo(EquipmentName::class, 'dumper_equipment_id');
    }

    public function excavator()
    {
        return $this->belongsTo(EquipmentName::class, 'excavator_equipment_id');
    }

    public function driver()
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function loadingPoint()
    {
        return $this->belongsTo(SitePoint::class, 'loading_point_id');
    }

    public function dumpingPoint()
    {
        return $this->belongsTo(SitePoint::class, 'dumping_point_id');
    }

    public function audits()
    {
        return $this->hasMany(DispatchTripAudit::class, 'dispatch_trip_id');
    }
}
