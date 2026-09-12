<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class ServiceRecord extends Model
{
    use SoftDeletes;

    /**
     * How many attachments a single service record may hold in total.
     *
     * Counted across what is already stored plus whatever an update is
     * uploading, so the cap holds over the life of the record and not just
     * per request.
     */
    const MAX_ATTACHMENTS = 3;

    protected $table = 'service_records';

    protected $fillable = [
        'ticket_number',
        'job_card_number',
        'machine_id',
        'site_id',
        'store_id',
        'is_breakdown_service',
        'breakdown_id',
        'service_type',
        'service_date',
        'hours_odometer_reading',
        'km_run',
        'time_gap_months',
        'downtime_start',
        'downtime_end',
        'downtime_minutes',
        'base_service_amount',
        'checklist_amount_total',
        'spare_parts_amount_total',
        'total_amount',
        'spare_parts_changed',
        'status',
        'performed_by',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_breakdown_service'     => 'boolean',
        'spare_parts_changed'      => 'boolean',
        'service_date'             => 'date',
        'hours_odometer_reading'   => 'decimal:2',
        'km_run'                   => 'decimal:2',
        'time_gap_months'          => 'integer',
        'downtime_start'           => 'datetime',
        'downtime_end'             => 'datetime',
        'downtime_minutes'         => 'integer',
        'base_service_amount'      => 'decimal:2',
        'checklist_amount_total'   => 'decimal:2',
        'spare_parts_amount_total' => 'decimal:2',
        'total_amount'             => 'decimal:2',
    ];

    /**
     * Generate sequential ticket number inside a transaction lock.
     * Format: SRV-YYYY-XXXXX (e.g. SRV-2026-00001)
     *
     * @return string
     */
    public static function generateTicketNumber()
    {
        $year = date('Y');
        $prefix = 'SRV-' . $year . '-';

        $latest = DB::table('service_records')
            ->where('ticket_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->first();

        if ($latest) {
            $numberPart = (int) substr($latest->ticket_number, strlen($prefix));
            $nextNumber = $numberPart + 1;
        } else {
            $nextNumber = 1;
        }

        return $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function breakdown()
    {
        return $this->belongsTo(Breakdown::class, 'breakdown_id');
    }

    /**
     * The single outside store this record's parts were drawn from, if any.
     */
    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function checklistDetail()
    {
        return $this->hasOne(ServiceChecklistDetail::class, 'service_record_id');
    }

    public function spareParts()
    {
        return $this->hasMany(ServiceSparePart::class, 'service_record_id');
    }

    public function attachments()
    {
        return $this->hasMany(ServiceAttachment::class, 'service_record_id');
    }

    public function statusHistory()
    {
        return $this->hasMany(ServiceStatusHistory::class, 'service_record_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(ServiceAuditLog::class, 'service_record_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
