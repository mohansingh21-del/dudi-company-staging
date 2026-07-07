<?php

namespace App\Services;

use App\Exceptions\ShiftAlreadyClosedException;
use App\Exceptions\ShiftClosureValidationException;
use App\Models\AttendanceProcessed;
use App\Models\BreakdownTicket;
use App\Models\Delay;
use App\Models\DispatchTrip;
use App\Models\FuelEntry;
use App\Models\Incident;
use App\Models\Leave;
use App\Models\ShiftClosureAuditLog;
use App\Models\ShiftEquipmentAllocation;
use App\Models\ShiftPlan;
use App\Models\ShiftWorkforceDeployment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShiftClosureService
{
    /*
    |--------------------------------------------------------------------------
    | GET /closure-summary — Pure read aggregation
    |--------------------------------------------------------------------------
    */

    /**
     * Build the consolidated closure summary for a shift plan.
     * Pure read — no side effects. Pulls from each module's live tables.
     *
     * @param  ShiftPlan  $shift
     * @return array
     */
    public function getClosureSummary(ShiftPlan $shift)
    {
        $shift->load(['shift', 'site', 'supervisor.employee', 'siteIncharge.employee', 'equipmentAllocations']);

        $shiftDetails = $this->buildShiftDetails($shift);
        $productionSummary = $this->buildProductionSummary($shift);
        $workforceSummary = $this->buildWorkforceSummary($shift);
        $equipmentSummary = $this->buildEquipmentSummary($shift);
        $operationalSummary = $this->buildOperationalSummary($shift);
        $validationChecklist = $this->buildValidationChecklist($shift);
        $warnings = $this->buildWarnings($shift, $productionSummary);

        return [
            'shift_details' => $shiftDetails,
            'production_summary' => $productionSummary,
            'workforce_summary' => $workforceSummary,
            'equipment_summary' => $equipmentSummary,
            'operational_summary' => $operationalSummary,
            'validation_checklist' => $validationChecklist,
            'warnings' => $warnings,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | POST /close — Transactional closure
    |--------------------------------------------------------------------------
    */

    /**
     * Finalize shift closure (BR-SHFT-022 through BR-SHFT-031).
     *
     * @param  ShiftPlan  $shift
     * @param  array      $validated
     * @param  User       $user
     * @return ShiftPlan
     *
     * @throws ShiftAlreadyClosedException
     * @throws ShiftClosureValidationException
     */
    public function closeShift(ShiftPlan $shift, array $validated, User $user)
    {
        return DB::transaction(function () use ($shift, $validated, $user) {

            // ── 1. Log audit ATTEMPT ───────────────────────────────
            $this->logAudit($shift->id, $user->id, 'attempt');

            // ── 2. Lock row to prevent race conditions ─────────────
            $shift = ShiftPlan::lockForUpdate()->find($shift->id);

            // ── 2a. Already completed? → 409 ──────────────────────
            if ($shift->status === 'completed') {
                $this->logAudit($shift->id, $user->id, 'failure', 'Shift Is Already Closed.');
                throw new ShiftAlreadyClosedException('Shift Is Already Closed.');
            }

            // ── 2b. Must be published or in_progress → else 422 ───
            if (!in_array($shift->status, ['published', 'in_progress'])) {
                $msg = 'Shift Must Be Published Or In Progress To View Closure Summary.';
                $this->logAudit($shift->id, $user->id, 'failure', $msg);
                throw new ShiftClosureValidationException($msg);
            }

            // ── 2c. Production data exists (EF-02) ────────────────
            if (!$this->hasProductionData($shift)) {
                $msg = 'Production Data Not Available.';
                $this->logAudit($shift->id, $user->id, 'failure', $msg);
                throw new ShiftClosureValidationException($msg);
            }

            // ── 2d. Workforce deployment exists (EF-03) ───────────
            if (!$this->hasWorkforceDeployment($shift)) {
                $msg = 'Workforce Deployment Must Be Completed Before Shift Closure.';
                $this->logAudit($shift->id, $user->id, 'failure', $msg);
                throw new ShiftClosureValidationException($msg);
            }

            // ── 2e. Attendance submitted (EF-04) ──────────────────
            if (!$this->hasAttendanceSubmitted($shift)) {
                $msg = 'Attendance Records Pending.';
                $this->logAudit($shift->id, $user->id, 'failure', $msg);
                throw new ShiftClosureValidationException($msg);
            }

            // ── 2f. Fuel logs complete (EF-05) ────────────────────
            if (!$this->hasFuelLogsCompleted($shift)) {
                $msg = 'Fuel Logs Missing For Assigned Equipment.';
                $this->logAudit($shift->id, $user->id, 'failure', $msg);
                throw new ShiftClosureValidationException($msg);
            }

            // ── 2g. Delay records reviewed (EF-06) ────────────────
            if (!$this->hasDelayRecordsReviewed($shift)) {
                $msg = 'Pending Delay Records Require Review.';
                $this->logAudit($shift->id, $user->id, 'failure', $msg);
                throw new ShiftClosureValidationException($msg);
            }

            // ── 3. Build & freeze snapshot (BR-SHFT-030) ──────────
            $shift->load(['shift', 'site', 'supervisor.employee', 'siteIncharge.employee', 'equipmentAllocations']);
            $snapshot = $this->getClosureSummary($shift);

            // ── 4. Update shift row ───────────────────────────────
            $now = Carbon::now();

            $shift->update([
                'status' => 'completed',
                'closed_by' => $user->id,
                'closure_date' => $now->toDateString(),
                'closure_time' => $now->format('H:i:s'),
                'supervisor_remarks' => $validated['supervisor_remarks'],
                'handover_notes' => isset($validated['handover_notes']) ? $validated['handover_notes'] : null,
                'closure_confirmed' => true,
                'breakdown_justification' => isset($validated['breakdown_justification']) ? $validated['breakdown_justification'] : null,
                'shift_summary_snapshot' => json_encode($snapshot),
            ]);

            // ── 5. Log audit SUCCESS ──────────────────────────────
            $this->logAudit($shift->id, $user->id, 'success');

            // ── 6. Reload relationships for response ──────────────
            $shift->load('closedByUser.employee');

            return $shift;
        });
    }

    /**
     * Check whether the given shift has an active (open/acknowledged) breakdown.
     * Used by controller to merge has_active_breakdown into the request.
     *
     * @param  ShiftPlan  $shift
     * @return bool
     */
    public function hasActiveBreakdown(ShiftPlan $shift)
    {
        $allocationIds = ShiftEquipmentAllocation::where('shift_plan_id', $shift->id)
            ->pluck('id')
            ->toArray();

        if (empty($allocationIds)) {
            return false;
        }

        return BreakdownTicket::whereIn('equipment_allocation_id', $allocationIds)
            ->whereIn('status', ['open', 'in_progress', 'on_hold', 'acknowledged'])
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Section Builders (used by getClosureSummary)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  ShiftPlan  $shift
     * @return array
     */
    private function buildShiftDetails(ShiftPlan $shift)
    {
        $supervisorName = null;
        $supervisor = $shift->supervisor;
        if ($supervisor) {
            $emp = $supervisor->employee;
            $supervisorName = $emp ? $emp->name : $supervisor->email;
        }

        $siteInchargeName = null;
        $siteIncharge = $shift->siteIncharge;
        if ($siteIncharge) {
            $emp = $siteIncharge->employee;
            $siteInchargeName = $emp ? $emp->name : $siteIncharge->email;
        }

        return [
            'shift_reference_number' => $shift->reference_no,
            'shift_date' => $shift->planning_date ? $shift->planning_date->format('Y-m-d') : null,
            'shift' => optional($shift->shift)->shift_name,
            'location' => optional($shift->site)->site_name,
            'supervisor' => $supervisorName,
            'site_incharge' => $siteInchargeName,
        ];
    }

    /**
     * @param  ShiftPlan  $shift
     * @return array
     */
    private function buildProductionSummary(ShiftPlan $shift)
    {
        // Actual BCM from dispatch trips (BR-SHFT-024)
        $actualBcm = (float) DispatchTrip::where('shift_plan_id', $shift->id)
            ->sum('quantity_bcm');

        $targetBcm = (float) $shift->target_bcm;

        $achievementPercent = $targetBcm > 0
            ? round(($actualBcm / $targetBcm) * 100, 2)
            : 0;

        return [
            'target_bcm' => (string) round($targetBcm, 2),
            'actual_bcm' => (string) round($actualBcm, 2),
            'achievement_percent' => (string) round($achievementPercent, 2),
        ];
    }

    /**
     * @param  ShiftPlan  $shift
     * @return array
     */
    private function buildWorkforceSummary(ShiftPlan $shift)
    {
        $planningDate = $shift->planning_date ? $shift->planning_date->format('Y-m-d') : null;

        // Planned workforce: employees assigned to this shift on this date
        $plannedWorkforce = 0;
        if ($planningDate) {
            $plannedWorkforce = \App\Models\Employee::where('is_active', true)
                ->whereHas('shiftAssignments', function ($q) use ($shift, $planningDate) {
                    $q->where('shift_id', $shift->shift_id)
                        ->where('from_date', '<=', $planningDate)
                        ->where(function ($sub) use ($planningDate) {
                            $sub->whereNull('to_date')
                                ->orWhere('to_date', '>=', $planningDate);
                        });
                })
                ->count();
        }

        // Present workforce: active deployments (non-borrowed)
        $presentWorkforce = ShiftWorkforceDeployment::where('shift_plan_id', $shift->id)
            ->active()
            ->count();

        // On leave
        $leaveCount = 0;
        if ($planningDate) {
            $leaveCount = Leave::where('status', 'approved')
                ->whereDate('from_date', '<=', $planningDate)
                ->whereDate('to_date', '>=', $planningDate)
                ->whereHas('employee', function ($q) use ($shift, $planningDate) {
                    $q->whereHas('shiftAssignments', function ($sq) use ($shift, $planningDate) {
                        $sq->where('shift_id', $shift->shift_id)
                            ->where('from_date', '<=', $planningDate)
                            ->where(function ($sub) use ($planningDate) {
                                $sub->whereNull('to_date')
                                    ->orWhere('to_date', '>=', $planningDate);
                            });
                    });
                })
                ->count();
        }

        // Borrowed employees
        $borrowedEmployees = ShiftWorkforceDeployment::where('shift_plan_id', $shift->id)
            ->active()
            ->borrowed()
            ->count();

        return [
            'planned_workforce' => $plannedWorkforce,
            'present_workforce' => $presentWorkforce,
            'leave_count' => $leaveCount,
            'borrowed_employees' => $borrowedEmployees,
        ];
    }

    /**
     * @param  ShiftPlan  $shift
     * @return array
     */
    private function buildEquipmentSummary(ShiftPlan $shift)
    {
        $allocations = ShiftEquipmentAllocation::where('shift_plan_id', $shift->id)->get();
        $allocationIds = $allocations->pluck('id')->toArray();

        // Count excavators & dumpers via equipment category name
        $excavatorsAssigned = ShiftEquipmentAllocation::where('shift_plan_id', $shift->id)
            ->whereHas('equipmentName.equipment', function ($q) {
                $q->whereRaw('LOWER(name) = ?', ['excavator']);
            })
            ->count();

        $dumpersAssigned = ShiftEquipmentAllocation::where('shift_plan_id', $shift->id)
            ->whereHas('equipmentName.equipment', function ($q) {
                $q->whereRaw('LOWER(name) = ?', ['dumper']);
            })
            ->count();

        // Breakdown equipment: allocations with any open/in_progress breakdown
        $breakdownEquipment = 0;
        if (!empty($allocationIds)) {
            $breakdownEquipment = BreakdownTicket::whereIn('equipment_allocation_id', $allocationIds)
                ->whereIn('status', ['open', 'in_progress', 'on_hold', 'acknowledged'])
                ->distinct('equipment_allocation_id')
                ->count('equipment_allocation_id');
        }

        $totalAllocated = $allocations->count();
        $activeEquipment = $totalAllocated - $breakdownEquipment;

        return [
            'excavators_assigned' => $excavatorsAssigned,
            'dumpers_assigned' => $dumpersAssigned,
            'active_equipment' => max(0, $activeEquipment),
            'breakdown_equipment' => $breakdownEquipment,
        ];
    }

    /**
     * @param  ShiftPlan  $shift
     * @return array
     */
    private function buildOperationalSummary(ShiftPlan $shift)
    {
        // Fuel consumed (BR-SHFT-025)
        $fuelConsumed = (float) FuelEntry::where('shift_plan_id', $shift->id)
            ->sum('fuel_consumption');

        // Delay hours (BR-SHFT-026)
        $delayMinutes = (float) Delay::where('shift_plan_id', $shift->id)
            ->sum('duration_minutes');
        $delayHours = round($delayMinutes / 60, 2);

        // Breakdown hours (BR-SHFT-028)
        $allocationIds = ShiftEquipmentAllocation::where('shift_plan_id', $shift->id)
            ->pluck('id')
            ->toArray();

        $breakdownMinutes = 0;
        if (!empty($allocationIds)) {
            $breakdownMinutes = (float) BreakdownTicket::whereIn('equipment_allocation_id', $allocationIds)
                ->sum('downtime_minutes');
        }
        $breakdownHours = round($breakdownMinutes / 60, 2);

        // Safety incidents (BR-SHFT-027)
        $safetyIncidents = Incident::where('shift_id', $shift->shift_id)
            ->whereDate('incident_date', optional($shift->planning_date)->format('Y-m-d'))
            ->count();

        return [
            'fuel_consumed' => (string) round($fuelConsumed, 2),
            'delay_hours' => (string) $delayHours,
            'breakdown_hours' => (string) $breakdownHours,
            'safety_incidents' => $safetyIncidents,
        ];
    }

    /**
     * @param  ShiftPlan  $shift
     * @return array
     */
    private function buildValidationChecklist(ShiftPlan $shift)
    {
        return [
            'workforce_deployment_completed' => $this->hasWorkforceDeployment($shift),
            'attendance_submitted' => $this->hasAttendanceSubmitted($shift),
            'production_data_available' => $this->hasProductionData($shift),
            'fuel_logs_completed' => $this->hasFuelLogsCompleted($shift),
            'delay_records_updated' => $this->hasDelayRecordsReviewed($shift),
            'breakdown_records_updated' => $this->hasBreakdownRecordsUpdated($shift),
            'safety_records_reviewed' => $this->hasSafetyRecordsReviewed($shift),
        ];
    }

    /**
     * @param  ShiftPlan  $shift
     * @param  array      $productionSummary
     * @return array
     */
    private function buildWarnings(ShiftPlan $shift, array $productionSummary)
    {
        return [
            'active_breakdown' => $this->hasActiveBreakdown($shift),
            'zero_production' => ((float) $productionSummary['actual_bcm']) == 0,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Precondition Checks
    |--------------------------------------------------------------------------
    */

    /**
     * @param  ShiftPlan  $shift
     * @return bool
     */
    private function hasProductionData(ShiftPlan $shift)
    {
        return DispatchTrip::where('shift_plan_id', $shift->id)->exists();
    }

    /**
     * @param  ShiftPlan  $shift
     * @return bool
     */
    private function hasWorkforceDeployment(ShiftPlan $shift)
    {
        return ShiftWorkforceDeployment::where('shift_plan_id', $shift->id)
            ->active()
            ->exists();
    }

    /**
     * @param  ShiftPlan  $shift
     * @return bool
     */
    private function hasAttendanceSubmitted(ShiftPlan $shift)
    {
        $planningDate = $shift->planning_date ? $shift->planning_date->format('Y-m-d') : null;

        if (!$planningDate) {
            return false;
        }

        // At least one processed attendance record for this shift on the planning date
        return AttendanceProcessed::where('shift_id', $shift->shift_id)
            ->whereDate('date', $planningDate)
            ->exists();
    }

    /**
     * @param  ShiftPlan  $shift
     * @return bool
     */
    private function hasFuelLogsCompleted(ShiftPlan $shift)
    {
        $assignedEquipmentCount = ShiftEquipmentAllocation::where('shift_plan_id', $shift->id)->count();

        if ($assignedEquipmentCount === 0) {
            return true; // No equipment assigned — nothing to log
        }

        $fuelLogCount = FuelEntry::where('shift_plan_id', $shift->id)->count();

        return $fuelLogCount >= $assignedEquipmentCount;
    }

    /**
     * @param  ShiftPlan  $shift
     * @return bool
     */
    private function hasDelayRecordsReviewed(ShiftPlan $shift)
    {
        // No incomplete/pending delay entries for this shift
        $pendingDelays = Delay::where('shift_plan_id', $shift->id)
            ->whereNull('end_time')
            ->count();

        return $pendingDelays === 0;
    }

    /**
     * @param  ShiftPlan  $shift
     * @return bool
     */
    private function hasBreakdownRecordsUpdated(ShiftPlan $shift)
    {
        $allocationIds = ShiftEquipmentAllocation::where('shift_plan_id', $shift->id)
            ->pluck('id')
            ->toArray();

        if (empty($allocationIds)) {
            return true; // No allocations — nothing to update
        }

        // True if no open/in_progress breakdowns remain (all are closed or don't exist)
        $openBreakdowns = BreakdownTicket::whereIn('equipment_allocation_id', $allocationIds)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();

        return $openBreakdowns === 0;
    }

    /**
     * @param  ShiftPlan  $shift
     * @return bool
     */
    private function hasSafetyRecordsReviewed(ShiftPlan $shift)
    {
        $planningDate = $shift->planning_date ? $shift->planning_date->format('Y-m-d') : null;

        if (!$planningDate) {
            return true;
        }

        // True if there are no open safety incidents for this shift on this date
        $openIncidents = Incident::where('shift_id', $shift->shift_id)
            ->whereDate('incident_date', $planningDate)
            ->where('status', 'open')
            ->count();

        return $openIncidents === 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Audit Logging (BR-SHFT-031)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  int         $shiftId
     * @param  int         $userId
     * @param  string      $action       attempt|success|failure
     * @param  string|null $failureReason
     * @return void
     */
    private function logAudit($shiftId, $userId, $action, $failureReason = null)
    {
        ShiftClosureAuditLog::create([
            'shift_id' => $shiftId,
            'user_id' => $userId,
            'action' => $action,
            'failure_reason' => $failureReason,
        ]);
    }
}
