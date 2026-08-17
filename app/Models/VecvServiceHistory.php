<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A dealer workshop job card pulled from the VECV Service History API.
 *
 * Not to be confused with ServiceRecord, which is this project's own record of
 * servicing carried out at the mine. These are third-party records of work
 * done at an Eicher dealership and are never authored locally - ingest is the
 * only writer.
 *
 * Unlike the telemetry readings these rows are mutable: a job card is amended
 * after opening, so it is updated in place on job_card_number.
 */
class VecvServiceHistory extends Model
{
    use HasFactory;

    protected $table = 'vecv_service_histories';

    protected $fillable = [
        'job_card_number',
        'chassis_number',
        'equipment_name_id',
        'registration_no',
        'dealer_name',
        'model_description',
        'order_type',
        'invoice_number',
        'odometer',
        'lube_value',
        'labour_value',
        'parts_value',
        'total_cost_customer',
        'job_card_open_date',
        'raw',
    ];

    protected $casts = [
        'equipment_name_id'   => 'integer',
        'odometer'            => 'decimal:2',
        'lube_value'          => 'decimal:2',
        'labour_value'        => 'decimal:2',
        'parts_value'         => 'decimal:2',
        'total_cost_customer' => 'decimal:2',
        'job_card_open_date'  => 'date',
        'raw'                 => 'array',
    ];

    protected $appends = [
        'computed_total',
        'is_invoiced',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function items()
    {
        return $this->hasMany(VecvServiceHistoryItem::class, 'vecv_service_history_id');
    }

    /**
     * The machine this job card belongs to, when the chassis has been matched.
     */
    public function machine()
    {
        return $this->belongsTo(EquipmentName::class, 'equipment_name_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Derived values
    |--------------------------------------------------------------------------
    */

    /**
     * Lube + labour + parts.
     *
     * totalCostCust is NOT this sum - it came back as "0" on a job card
     * carrying 3943.00 of line values, which is consistent with it meaning
     * "amount payable by the customer" on warranty or AMC work rather than
     * "total cost of the job". Use this attribute for job value; use
     * total_cost_customer only when you specifically mean customer liability.
     *
     * @return float
     */
    public function getComputedTotalAttribute()
    {
        return round(
            (float) $this->lube_value
            + (float) $this->labour_value
            + (float) $this->parts_value,
            2
        );
    }

    /**
     * Whether the job card has been billed yet.
     *
     * @return bool
     */
    public function getIsInvoicedAttribute()
    {
        return ! empty($this->invoice_number);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeForChassis($query, $chassisNumber)
    {
        return $query->where('chassis_number', trim($chassisNumber));
    }

    public function scopeForMachine($query, $equipmentNameId)
    {
        return $query->where('equipment_name_id', $equipmentNameId);
    }

    /**
     * Job cards opened between two dates, most recent first.
     */
    public function scopeOpenedBetween($query, $from, $to)
    {
        return $query->whereBetween('job_card_open_date', [$from, $to])
            ->orderByDesc('job_card_open_date');
    }

    /**
     * Job cards still awaiting an invoice.
     */
    public function scopeUninvoiced($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('invoice_number')->orWhere('invoice_number', '');
        });
    }
}
