<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftPlan extends Model
{
    use HasFactory;

    protected $table = 'shift_plans';

    /**
     * Largest value the decimal(10,2) BCM columns can hold.
     */
    const MAX_BCM = 99999999.99;

    /**
     * Most supervisors a single shift plan may have.
     */
    const MAX_SUPERVISORS = 5;

    protected $fillable = [
        'planning_date',
        'shift_id',
        'site_id',
        'target_bcm',
        'supervisor_id',
        'site_incharge_id',
        'equipment_count',
        'status',
        'created_by',
        'reference_no',
        'actual_bcm',
        'published_by',
        'published_at',
        'closed_by',
        'closure_date',
        'closure_time',
        'supervisor_remarks',
        'handover_notes',
        'closure_confirmed',
        'breakdown_justification',
        'shift_summary_snapshot',
    ];

    protected $casts = [
        'planning_date'     => 'date',
        'target_bcm'        => 'decimal:2',
        'actual_bcm'        => 'decimal:2',
        'equipment_count'   => 'integer',
        'published_at'      => 'datetime',
        'closure_date'      => 'date',
        'closure_confirmed' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Supervisors
    |--------------------------------------------------------------------------
    | supervisor_id holds comma-separated user IDs ("3,7,12"), primary first.
    */

    /**
     * Supervisor users, loaded by loadSupervisors() or on first use.
     *
     * @var \Illuminate\Support\Collection|null
     */
    protected $supervisorUsers = null;

    /**
     * supervisor_id value $supervisorUsers was loaded for.
     *
     * @var string|null
     */
    protected $supervisorUsersFor = null;

    /**
     * Supervisor user IDs, in stored order.
     *
     * @return int[]
     */
    public function supervisorIds()
    {
        return self::parseSupervisorIds($this->supervisor_id);
    }

    /**
     * Primary (first) supervisor user ID.
     *
     * @return int|null
     */
    public function primarySupervisorId()
    {
        $ids = $this->supervisorIds();

        return $ids ? $ids[0] : null;
    }

    /**
     * @param  mixed  $value
     * @return int[]
     */
    public static function parseSupervisorIds($value)
    {
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Restrict a query to plans the given user supervises.
     */
    public function scopeSupervisedBy($query, $userId)
    {
        return $query->whereRaw('FIND_IN_SET(?, shift_plans.supervisor_id)', [(int) $userId]);
    }

    /**
     * Load supervisor users (with employee) for many plans in one query.
     *
     * @param  iterable  $plans
     * @return void
     */
    public static function loadSupervisors($plans)
    {
        $plans = collect($plans);
        $ids = $plans->flatMap(function ($plan) {
            return $plan->supervisorIds();
        })->unique()->values();

        $users = $ids->isEmpty()
            ? collect()
            : User::with('employee')->whereIn('id', $ids)->get()->keyBy('id');

        $plans->each(function ($plan) use ($users) {
            $plan->supervisorUsersFor = (string) $plan->supervisor_id;
            $plan->supervisorUsers = collect($plan->supervisorIds())->map(function ($id) use ($users) {
                return $users->get($id);
            })->filter()->values();
        });
    }

    /**
     * Supervisor users, primary first.
     *
     * @return \Illuminate\Support\Collection
     */
    public function supervisors()
    {
        if (is_null($this->supervisorUsers) || $this->supervisorUsersFor !== (string) $this->supervisor_id) {
            self::loadSupervisors([$this]);
        }

        return $this->supervisorUsers;
    }

    /**
     * Supervisors as display rows (employee id/name/code), primary first.
     *
     * @return array
     */
    public function supervisorList()
    {
        return $this->supervisors()->map(function ($user) {
            $employee = $user->employee;

            return [
                'id' => $employee ? $employee->id : $user->id,
                'user_id' => $user->id,
                'name' => $employee ? $employee->name : $user->email,
                'code' => $employee ? $employee->employee_code : null,
            ];
        })->values()->all();
    }

    /**
     * Comma-separated supervisor names, for summaries.
     *
     * @return string|null
     */
    public function supervisorNames()
    {
        $names = array_filter(array_column($this->supervisorList(), 'name'));

        return $names ? implode(', ', $names) : null;
    }

    public function siteIncharge()
    {
        return $this->belongsTo(User::class, 'site_incharge_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function equipmentAllocations()
    {
        return $this->hasMany(ShiftEquipmentAllocation::class, 'shift_plan_id');
    }

    public function workforceDeployments()
    {
        return $this->hasMany(ShiftWorkforceDeployment::class, 'shift_plan_id');
    }

    public function closureAuditLogs()
    {
        return $this->hasMany(ShiftClosureAuditLog::class, 'shift_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Shifts that are NOT closed — considered "active" for allocation exclusion.
     */
    public function scopeNotClosed($query)
    {
        return $query->whereNotIn('status', ['closed', 'completed']);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'planned']);
    }
}
