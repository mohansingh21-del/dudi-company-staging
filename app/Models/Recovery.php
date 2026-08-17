<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recovery extends Model
{
    use HasFactory;

    protected $fillable = [
        'recovery_upload_id',
        'employee_id',
        'employee_code',
        'employee_name',
        'recovery_type',
        'particulars',
        'damage_loss_date',
        'amount',
        'show_cause_issued',
        'explanation_witness',
        'number_of_installments',
        'first_month_year',
        'last_month_year',
        'complete_recovery_date',
        'remarks',
    ];

    protected $casts = [
        'damage_loss_date' =>
        'date:Y-m-d',

        'complete_recovery_date' =>
        'date:Y-m-d',

        'amount' =>
        'decimal:2',

        'show_cause_issued' =>
        'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(
            Employee::class
        );
    }

    public function upload()
    {
        return $this->belongsTo(
            RecoveryUpload::class,
            'recovery_upload_id'
        );
    }
}
