<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceProcessed extends Model
{
    use HasFactory;

    protected static $resolvedTable;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if (!static::$resolvedTable) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('attendance_processeds')) {
                    static::$resolvedTable = 'attendance_processeds';
                } else {
                    static::$resolvedTable = 'attendance_processed';
                }
            } catch (\Throwable $e) {
                static::$resolvedTable = 'attendance_processed';
            }
        }

        $this->setTable(static::$resolvedTable);
    }

    protected $fillable = [
        'employee_id',
        'shift_id',
        'date',
        'check_in',
        'check_out',
        'working_hours',
        'late_minutes',
        'early_exit_minutes',
        'attendance_status',
        'remarks',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'working_hours' => 'decimal:2',
    ];
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}
