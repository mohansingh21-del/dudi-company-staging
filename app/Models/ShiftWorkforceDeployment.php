<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftWorkforceDeployment extends Model
{
    use HasFactory;

    protected $table = 'shift_workforce_deployments';

    protected $fillable = [
        'shift_plan_id',
        'employee_id',
        'relay_id',
        'home_relay_id',
        'assigned_machine_id',
        'designation',
        'is_borrowed',
        'borrowing_reason',
        'borrowed_by',
        'borrowed_at',
        'deployed_by',
        'status',
        'removed_reason',
    ];

    protected $casts = [
        'is_borrowed' => 'boolean',
        'borrowed_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The shift plan this deployment belongs to.
     */
    public function shiftPlan()
    {
        return $this->belongsTo(ShiftPlan::class, 'shift_plan_id');
    }

    /**
     * The deployed employee.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * The deployed employee's active relay.
     */
    public function relay()
    {
        return $this->belongsTo(Relay::class, 'relay_id');
    }

    /**
     * The deployed employee's home relay.
     */
    public function homeRelay()
    {
        return $this->belongsTo(Relay::class, 'home_relay_id');
    }

    /**
     * The machine assigned to this employee for the shift (nullable).
     */
    public function assignedMachine()
    {
        return $this->belongsTo(ShiftEquipmentAllocation::class, 'assigned_machine_id');
    }

    /**
     * The user who borrowed this employee (nullable).
     */
    public function borrower()
    {
        return $this->belongsTo(User::class, 'borrowed_by');
    }

    /**
     * The user who deployed this employee.
     */
    public function deployer()
    {
        return $this->belongsTo(User::class, 'deployed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Filter to only active deployments (not removed).
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Filter to borrowed employees only.
     */
    public function scopeBorrowed($query)
    {
        return $query->where('is_borrowed', true);
    }

    /**
     * Filter to regular (non-borrowed) employees.
     */
    public function scopeRegular($query)
    {
        return $query->where('is_borrowed', false);
    }
}
