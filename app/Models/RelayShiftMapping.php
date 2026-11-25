<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RelayShiftMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'week_start_date',
        'week_end_date',
        'relay_id',
        'shift_id',
    ];

    protected $casts = [
        'week_start_date' => 'date:Y-m-d',
        'week_end_date' => 'date:Y-m-d',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function relay()
    {
        return $this->belongsTo(Relay::class);
    }

    /**
     * Get the mapping for a specific relay on a specific date.
     */
    public static function getForDate($relayId, $dateStr)
    {
        return static::where('relay_id', $relayId)
            ->where('week_start_date', '<=', $dateStr)
            ->where('week_end_date', '>=', $dateStr)
            ->first();
    }

    /**
     * Get the mapping holding a shift for the week containing $dateStr, if any.
     * A shift belongs to at most one relay per week, so this identifies the
     * relay that a would-be assignment of $shiftId would collide with.
     */
    public static function getHolderOfShift($shiftId, $dateStr, $exceptRelayId = null)
    {
        return static::with('relay')
            ->where('shift_id', $shiftId)
            ->where('week_start_date', '<=', $dateStr)
            ->where('week_end_date', '>=', $dateStr)
            ->when($exceptRelayId, function ($q) use ($exceptRelayId) {
                $q->where('relay_id', '!=', $exceptRelayId);
            })
            ->first();
    }

    /**
     * Get the latest mapping for a relay (most recent week).
     */
    public static function getLatestForRelay($relayId)
    {
        return static::where('relay_id', $relayId)
            ->orderBy('week_start_date', 'desc')
            ->first();
    }
}
