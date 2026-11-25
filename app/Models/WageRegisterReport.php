<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A frozen Form B wage register for one month. Rows are a snapshot — they are
 * never recomputed on read, which is the whole point of generating one.
 *
 * At most one report exists per month. Generating a month that already has one
 * replaces it and increments `version`, so the list shows how many times it has
 * been redone without filling up with duplicates.
 */
class WageRegisterReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'month',
        'year',
        'version',
        'generated_by',
        'generated_at',
        'employee_count',
        'total_earnings',
        'total_deductions',
        'total_net',
        'wage_rate_snapshot',
        'remarks',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'version' => 'integer',
        'employee_count' => 'integer',
        'total_earnings' => 'float',
        'total_deductions' => 'float',
        'total_net' => 'float',
        'generated_at' => 'datetime',
        'wage_rate_snapshot' => 'array',
    ];

    public function rows()
    {
        return $this->hasMany(WageRegisterReportRow::class, 'report_id')->orderBy('serial_no');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public static function forMonth(int $month, int $year): ?self
    {
        return static::where('month', $month)->where('year', $year)->first();
    }

    public function getMonthLabelAttribute(): string
    {
        return \Carbon\Carbon::create($this->year, $this->month, 1)->format('F Y');
    }
}
