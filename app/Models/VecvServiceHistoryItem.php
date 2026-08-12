<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One line on a VECV dealer job card - a part fitted or a job performed.
 *
 * Keyed on the vendor's rowId UUID, so re-reading a job card never duplicates
 * its lines.
 */
class VecvServiceHistoryItem extends Model
{
    use HasFactory;

    protected $table = 'vecv_service_history_items';

    protected $fillable = [
        'vecv_service_history_id',
        'row_id',
        'job_description',
        'job_id_description',
        'action',
        'observation',
        'material_code',
        'material_type',
        'job_type',
        'part_qty',
        'part_total_amount',
        'part_total_amount_with_gst',
        'raw',
    ];

    protected $casts = [
        'vecv_service_history_id'    => 'integer',
        'part_qty'                   => 'decimal:2',
        'part_total_amount'          => 'decimal:2',
        'part_total_amount_with_gst' => 'decimal:2',
        'raw'                        => 'array',
    ];

    protected $appends = [
        'gst_amount',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function serviceHistory()
    {
        return $this->belongsTo(VecvServiceHistory::class, 'vecv_service_history_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Derived values
    |--------------------------------------------------------------------------
    */

    /**
     * Tax component of the line.
     *
     * The vendor sends both the ex-GST and inclusive amounts but never the tax
     * itself, and the observed pair (152.54 / 180.00) is not a clean rate, so
     * it is differenced rather than assumed.
     *
     * @return float|null
     */
    public function getGstAmountAttribute()
    {
        if ($this->part_total_amount === null || $this->part_total_amount_with_gst === null) {
            return null;
        }

        return round((float) $this->part_total_amount_with_gst - (float) $this->part_total_amount, 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Lines of a given material type, e.g. "Spare Parts".
     */
    public function scopeOfMaterialType($query, $type)
    {
        return $query->where('material_type', $type);
    }

    /**
     * Lines belonging to a given job type, e.g. "AMC".
     */
    public function scopeOfJobType($query, $type)
    {
        return $query->where('job_type', $type);
    }
}
