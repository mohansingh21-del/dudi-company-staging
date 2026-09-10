<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftOverride;
use App\Models\Relay;
use App\Models\RelayShiftMapping;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftPlan;
use App\Models\ShiftWorkforceDeployment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A deployment sitting on a CLOSED shift plan no longer occupies the employee:
 * once their shift is overridden onto another shift for the same date, load-relay
 * must deploy them into that shift's plan. Deployments on plans that are still
 * open keep blocking exactly as before.
 */
class WorkforceDeploymentClosedShiftReleaseTest extends TestCase
{
    use RefreshDatabase;

    private $adminUser;
    private $shiftA;
    private $shiftB;
    private $site;
    private $relayA;
    private $relayB;
    private $employee;
    private $planningDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planningDate = now()->toDateString();

        $role = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);
        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
        ]);
        $this->adminUser->roles()->attach($role);
        Sanctum::actingAs($this->adminUser);

        $this->shiftA = Shift::create([
            'shift_name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1,
        ]);

        $this->shiftB = Shift::create([
            'shift_name' => 'Evening Shift',
            'start_time' => '16:00:00',
            'end_time' => '23:59:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1,
        ]);

        $this->site = Site::create(['site_name' => 'Block-04 West', 'is_active' => 1]);

        $weekStart = now()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
        $weekEnd = now()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(6)->toDateString();

        $this->relayA = Relay::create(['name' => 'Relay A', 'is_rotating' => true, 'is_active' => true]);
        $this->relayB = Relay::create(['name' => 'Relay B', 'is_rotating' => true, 'is_active' => true]);

        RelayShiftMapping::create([
            'week_start_date' => $weekStart,
            'week_end_date' => $weekEnd,
            'relay_id' => $this->relayA->id,
            'shift_id' => $this->shiftA->id,
        ]);
        RelayShiftMapping::create([
            'week_start_date' => $weekStart,
            'week_end_date' => $weekEnd,
            'relay_id' => $this->relayB->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $this->employee = Employee::create([
            'employee_code' => 'EMP-001',
            'name' => 'Ramesh Kumar',
            'joining_date' => '2026-01-01',
            'relay_id' => $this->relayA->id,
            'is_active' => 1,
        ]);

        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => $this->planningDate,
        ]);
    }

    private function makePlan(Shift $shift, $status, $reference)
    {
        return ShiftPlan::create([
            'planning_date' => $this->planningDate,
            'shift_id' => $shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'status' => $status,
            'created_by' => $this->adminUser->id,
            'reference_no' => $reference,
        ]);
    }

    private function deploy(ShiftPlan $plan, Employee $employee, $isBorrowed = false)
    {
        return ShiftWorkforceDeployment::create([
            'shift_plan_id' => $plan->id,
            'employee_id' => $employee->id,
            'relay_id' => $employee->relay_id,
            'is_borrowed' => $isBorrowed,
            'deployed_by' => $this->adminUser->id,
            'status' => 'active',
        ]);
    }

    public function test_employee_overridden_after_their_shift_closed_is_deployed_into_the_new_shift()
    {
        // Morning shift ran and was closed with the employee on it.
        $planA = $this->makePlan($this->shiftA, 'completed', 'SP-A-001');
        $this->deploy($planA, $this->employee);

        // Supervisor moves them onto the evening shift — allowed, plan A is closed.
        $response = $this->postJson('/api/v1/admin/shift-rotation/override', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);
        $response->assertStatus(200);

        // Evening shift plan loads its relay.
        $planB = $this->makePlan($this->shiftB, 'draft', 'SP-B-001');
        $response = $this->postJson("/api/v1/admin/shift-plans/{$planB->id}/workforce/load-relay");

        $response->assertStatus(200);
        $this->assertDatabaseHas('shift_workforce_deployments', [
            'shift_plan_id' => $planB->id,
            'employee_id' => $this->employee->id,
            'status' => 'active',
            'is_borrowed' => 0,
        ]);

        // The closed plan's own workforce record is left untouched.
        $this->assertDatabaseHas('shift_workforce_deployments', [
            'shift_plan_id' => $planA->id,
            'employee_id' => $this->employee->id,
            'status' => 'active',
        ]);
    }

    public function test_employee_deployed_on_an_open_plan_is_still_skipped_by_load_relay()
    {
        // Employee's own shift is B, but they are borrowed into the morning shift,
        // which is still running.
        EmployeeShiftOverride::create([
            'employee_id' => $this->employee->id,
            'effective_from' => $this->planningDate,
            'shift_id' => $this->shiftB->id,
            'reason' => 'Shift override',
            'created_by' => $this->adminUser->id,
        ]);

        $planA = $this->makePlan($this->shiftA, 'in_progress', 'SP-A-002');
        $this->deploy($planA, $this->employee, true);

        $planB = $this->makePlan($this->shiftB, 'draft', 'SP-B-002');
        $response = $this->postJson("/api/v1/admin/shift-plans/{$planB->id}/workforce/load-relay");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('shift_workforce_deployments', [
            'shift_plan_id' => $planB->id,
            'employee_id' => $this->employee->id,
        ]);
    }

    public function test_load_relay_does_not_duplicate_rows_when_the_plan_itself_is_closed()
    {
        $planA = $this->makePlan($this->shiftA, 'completed', 'SP-A-003');
        $this->deploy($planA, $this->employee);

        // Loading the relay of an already-closed plan must stay idempotent on its
        // own rows rather than deploying the employee a second time.
        $this->postJson("/api/v1/admin/shift-plans/{$planA->id}/workforce/load-relay")
            ->assertStatus(200);

        $this->assertEquals(1, ShiftWorkforceDeployment::where('shift_plan_id', $planA->id)
            ->where('employee_id', $this->employee->id)
            ->count());
    }

    public function test_workforce_list_stops_reporting_a_closed_plan_as_deployed_elsewhere()
    {
        $planA = $this->makePlan($this->shiftA, 'completed', 'SP-A-004');
        $this->deploy($planA, $this->employee);

        EmployeeShiftOverride::create([
            'employee_id' => $this->employee->id,
            'effective_from' => $this->planningDate,
            'shift_id' => $this->shiftB->id,
            'reason' => 'Shift override',
            'created_by' => $this->adminUser->id,
        ]);

        $planB = $this->makePlan($this->shiftB, 'draft', 'SP-B-004');

        $response = $this->getJson("/api/v1/admin/shift-plans/{$planB->id}/workforce");
        $response->assertStatus(200);

        $row = collect($response->json('data'))
            ->firstWhere('employee_id', $this->employee->id);

        $this->assertNotNull($row, 'Overridden employee should appear on the evening shift list.');
        $this->assertSame('Not Deployed', $row['status']);
    }

    public function test_open_plan_is_still_reported_as_deployed_elsewhere()
    {
        $planA = $this->makePlan($this->shiftA, 'in_progress', 'SP-A-005');
        $this->deploy($planA, $this->employee);

        EmployeeShiftOverride::create([
            'employee_id' => $this->employee->id,
            'effective_from' => $this->planningDate,
            'shift_id' => $this->shiftB->id,
            'reason' => 'Shift override',
            'created_by' => $this->adminUser->id,
        ]);

        $planB = $this->makePlan($this->shiftB, 'draft', 'SP-B-005');

        $response = $this->getJson("/api/v1/admin/shift-plans/{$planB->id}/workforce");
        $response->assertStatus(200);

        $row = collect($response->json('data'))
            ->firstWhere('employee_id', $this->employee->id);

        $this->assertNotNull($row);
        $this->assertSame('Deployed in Morning Shift', $row['status']);
    }
}
