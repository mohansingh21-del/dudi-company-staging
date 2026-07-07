<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftClosureAuditLog extends Model
{
    use HasFactory;

    protected $table = 'shift_closure_audit_logs';

    protected $fillable = [
        'shift_id',
        'user_id',
        'action',
        'failure_reason',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The shift plan this audit entry belongs to.
     */
    public function shiftPlan()
    {
        return $this->belongsTo(ShiftPlan::class, 'shift_id');
    }

    /**
     * The user who performed the action.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
