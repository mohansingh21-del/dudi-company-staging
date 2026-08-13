<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A frozen Form E register for one year. Rows are a snapshot — they are never
 * recomputed on read, which is the whole point of generating one.
 *
 * At most one report exists per year. Generating a year that already has one
 * replaces it and increments `version`, so the row shows how many times it has
 * been redone without leaving duplicates in the list.
 */
class LeaveRegisterReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'version',
        'generated_by',
        'generated_at',
        'employee_count',
        'leave_type_snapshot',
        'remarks',
    ];

    protected $casts = [
        'year' => 'integer',
        'version' => 'integer',
        'employee_count' => 'integer',
        'generated_at' => 'datetime',
        'leave_type_snapshot' => 'array',
    ];

    public function rows()
    {
        return $this->hasMany(LeaveRegisterReportRow::class, 'report_id')->orderBy('serial_no');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public static function forYear(int $year): ?self
    {
        return static::where('year', $year)->first();
    }
}
