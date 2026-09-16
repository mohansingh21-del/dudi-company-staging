<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
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
 * BR: an employee still actively deployed on a shift plan that has not been closed
 * cannot have their shift changed from the Shift Rotation module. The change would
 * rewrite their relay and write an open-ended override, leaving the open plan's
 * workforce, machine allocations and attendance pointing at a shift the employee
 * no longer resolves to.
 */
class ShiftChangeOpenDeploymentGuardTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $employee;
    protected $shiftA;
    protected $shiftB;
    protected $relayA;
    protected $site;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1,
        ]);

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
            'shift_name' => 'Night Shift',
            'start_time' => '20:00:00',
            'end_time' => '04:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 1,
            'is_active' => 1,
        ]);

        $this->relayA = Relay::create(['name' => 'Relay A', 'is_rotating' => true, 'is_active' => true]);

        $this->site = Site::create(['site_name' => 'Site Alpha', 'is_active' => true]);

        $this->employee = Employee::create([
            'employee_code' => 'EMP123',
            'name' => 'Neha Jain',
            'joining_date' => '2026-01-01',
            'relay_id' => $this->relayA->id,
            'is_active' => 1,
        ]);

        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => now()->toDateString(),
        ]);

        // Shift B must be owned by a relay: changing shift also moves the employee
        // into that shift's relay.
        $relayB = Relay::create(['name' => 'Relay B', 'is_rotating' => true, 'is_active' => true]);
        RelayShiftMapping::create([
            'week_start_date' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(),
            'week_end_date' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(6)->toDateString(),
            'relay_id' => $relayB->id,
            'shift_id' => $this->shiftB->id,
        ]);
    }

    private function makePlan($status, $reference = 'SP-001')
    {
        return ShiftPlan::create([
            'planning_date' => now()->toDateString(),
            'shift_id' => $this->shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1500.00,
            'supervisor_id' => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'status' => $status,
            'created_by' => $this->adminUser->id,
            'reference_no' => $reference,
        ]);
    }

    private function deploy(ShiftPlan $plan, Employee $employee, $status = 'active')
    {
        return ShiftWorkforceDeployment::create([
            'shift_plan_id' => $plan->id,
            'employee_id' => $employee->id,
            'relay_id' => $employee->relay_id,
            'is_borrowed' => false,
            'deployed_by' => $this->adminUser->id,
            'status' => $status,
        ]);
    }

    public function test_override_is_blocked_while_deployed_on_an_open_shift_plan()
    {
        $this->deploy($this->makePlan('in_progress'), $this->employee);

        $response = $this->postJson('/api/v1/admin/shift-rotation/override', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Morning Shift', $response->json('message'));
        $this->assertStringContainsString('not closed yet', $response->json('message'));

        $this->assertDatabaseMissing('employee_shift_overrides', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
        ]);
    }

    public function test_update_route_is_blocked_while_deployed_on_an_open_shift_plan()
    {
        $this->deploy($this->makePlan('draft'), $this->employee);

        $response = $this->patchJson("/api/v1/admin/shift-rotation/{$this->employee->id}", [
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('employee_shift_overrides', ['employee_id' => $this->employee->id]);
    }

    public function test_bulk_shift_change_is_blocked_and_lists_every_blocked_employee()
    {
        $other = Employee::create([
            'employee_code' => 'EMP124',
            'name' => 'Amit Rao',
            'joining_date' => '2026-01-01',
            'relay_id' => $this->relayA->id,
            'is_active' => 1,
        ]);

        $plan = $this->makePlan('published');
        $this->deploy($plan, $this->employee);
        $this->deploy($plan, $other);

        $response = $this->postJson('/api/v1/admin/shift-rotation', [
            'employee_ids' => [$this->employee->id, $other->id],
            'target_shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(422);
        $this->assertCount(2, $response->json('data.blocked'));
        $this->assertDatabaseCount('employee_shift_overrides', 0);
    }

    public function test_swap_is_blocked_when_either_employee_is_deployed_on_an_open_plan()
    {
        $relayC = Relay::create(['name' => 'Relay C', 'is_rotating' => true, 'is_active' => true]);
        $other = Employee::create([
            'employee_code' => 'EMP125',
            'name' => 'Priya Shah',
            'joining_date' => '2026-01-01',
            'relay_id' => $relayC->id,
            'is_active' => 1,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $other->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => now()->toDateString(),
        ]);

        // Only the second employee is deployed — the swap must still be refused.
        $this->deploy($this->makePlan('active'), $other);

        $response = $this->postJson('/api/v1/admin/shift-rotation/swap', [
            'employee_id' => $this->employee->id,
            'swap_with_employee_id' => $other->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('employee_shift_overrides', 0);
    }

    public function test_override_is_allowed_once_the_shift_plan_is_closed()
    {
        $this->deploy($this->makePlan('completed'), $this->employee);

        $response = $this->postJson('/api/v1/admin/shift-rotation/override', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('employee_shift_overrides', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);
    }

    public function test_override_is_allowed_when_the_employee_was_removed_from_the_open_plan()
    {
        $this->deploy($this->makePlan('in_progress'), $this->employee, 'removed');

        $response = $this->postJson('/api/v1/admin/shift-rotation/override', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('employee_shift_overrides', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);
    }
}
