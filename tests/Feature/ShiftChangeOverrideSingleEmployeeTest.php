<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftChangeOverrideSingleEmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $employee;
    protected $shiftA;
    protected $shiftB;
    protected $relayA;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the super-admin role
        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        // Create super-admin user
        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($role);

        // Authenticate with Sanctum
        Sanctum::actingAs($this->adminUser);

        // Create shifts
        $this->shiftA = Shift::create([
            'shift_name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        $this->shiftB = Shift::create([
            'shift_name' => 'Night Shift',
            'start_time' => '20:00:00',
            'end_time' => '04:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 1,
            'is_active' => 1
        ]);

        // Create relay A
        $this->relayA = \App\Models\Relay::create(['name' => 'Relay A', 'is_rotating' => true, 'is_active' => true]);

        // Create employee
        $this->employee = Employee::create([
            'employee_code' => 'EMP123',
            'name' => 'Neha Jain',
            'joining_date' => '2026-01-01',
            'relay_id' => $this->relayA->id,
            'is_active' => 1
        ]);
    }

    /**
     * Give Shift B an owning relay for the current week and return it.
     */
    private function mapRelayToShiftB($name)
    {
        $relay = \App\Models\Relay::create([
            'name' => $name,
            'is_rotating' => true,
            'is_active' => true,
        ]);

        \App\Models\RelayShiftMapping::create([
            'week_start_date' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(),
            'week_end_date' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(6)->toDateString(),
            'relay_id' => $relay->id,
            'shift_id' => $this->shiftB->id,
        ]);

        return $relay;
    }

    public function test_can_override_shift_using_update_route()
    {
        // Setup initial shift A assignment
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => now()->toDateString(),
        ]);

        // Shift B must be owned by a relay: overriding an employee onto it also moves
        // them into that relay.
        $this->mapRelayToShiftB('Relay B');

        // Request shift override via PATCH/PUT route
        $response = $this->patchJson("/api/v1/admin/shift-rotation/{$this->employee->id}", [
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Shift override applied successfully. Shift changed to Night Shift.'
        ]);

        // Assert database has updated shift to B
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        // Assert history recorded with performing user's id
        $this->assertDatabaseHas('employee_shift_histories', [
            'employee_id' => $this->employee->id,
            'old_shift_id' => $this->shiftA->id,
            'new_shift_id' => $this->shiftB->id,
            'user_id' => $this->adminUser->id,
        ]);
    }

    public function test_can_override_shift_using_custom_post_route()
    {
        // Setup initial shift A assignment
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => now()->toDateString(),
        ]);

        // Shift B must be owned by a relay: overriding an employee onto it also moves
        // them into that relay.
        $this->mapRelayToShiftB('Relay B');

        // Request shift override via POST route
        $response = $this->postJson("/api/v1/admin/shift-rotation/override", [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Shift override applied successfully. Shift changed to Night Shift.'
        ]);

        // Assert database has updated shift to B
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        // Assert history recorded with performing user's id
        $this->assertDatabaseHas('employee_shift_histories', [
            'employee_id' => $this->employee->id,
            'old_shift_id' => $this->shiftA->id,
            'new_shift_id' => $this->shiftB->id,
            'user_id' => $this->adminUser->id,
        ]);
    }

    public function test_can_filter_index_by_from_date_and_to_date()
    {
        // 1. Create an employee with assignment starting 2026-06-10
        $employee1 = Employee::create([
            'employee_code' => 'EMP789',
            'name' => 'Amit Singh',
            'joining_date' => '2026-01-01',
            'relay_id' => $this->relayA->id,
            'is_active' => 1
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee1->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-06-10',
        ]);

        // 2. Create another employee with assignment starting 2026-06-20
        $employee2 = Employee::create([
            'employee_code' => 'EMP999',
            'name' => 'Sanjay Dutt',
            'joining_date' => '2026-01-01',
            'relay_id' => $this->relayA->id,
            'is_active' => 1
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee2->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-06-20',
        ]);

        // 3. Filter index with from_date = 2026-06-15
        $response = $this->getJson('/api/v1/admin/shift-rotation?from_date=2026-06-15');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('Sanjay Dutt', $response->json('data.0.name'));

        // 4. Filter index with to_date = 2026-06-15
        $response = $this->getJson('/api/v1/admin/shift-rotation?to_date=2026-06-15');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('Amit Singh', $response->json('data.0.name'));
    }

    public function test_can_filter_index_when_from_date_is_null_falls_back_to_created_at()
    {
        // 1. Create an employee with assignment having NULL from_date, created on 2026-06-12
        $employee = Employee::create([
            'employee_code' => 'EMP404',
            'name' => 'Null From Date Emp',
            'joining_date' => '2026-01-01',
            'relay_id' => $this->relayA->id,
            'is_active' => 1
        ]);
        
        \DB::table('employee_shift_assignments')->insert([
            'employee_id' => $employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => null,
            'created_at' => '2026-06-12 10:00:00',
            'updated_at' => '2026-06-12 10:00:00',
        ]);

        // 2. Filter index with from_date = 2026-06-10 (should match, since created_at is 2026-06-12)
        $response = $this->getJson('/api/v1/admin/shift-rotation?from_date=2026-06-10');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals('Null From Date Emp', $response->json('data.0.name'));

        // 3. Filter index with from_date = 2026-06-15 (should not match, since created_at is 2026-06-12)
        $response = $this->getJson('/api/v1/admin/shift-rotation?from_date=2026-06-15');
        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_relay_changes_permanently_to_mapped_relay_when_shift_is_overridden()
    {
        // 1. Create a second relay that owns Shift B for this week
        $relayB = $this->mapRelayToShiftB('Relay B');

        // 3. Set employee initial shift to shiftA
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => now()->toDateString(),
        ]);

        $this->assertEquals($this->relayA->id, $this->employee->relay_id);

        // 4. Override shift to shiftB
        $response = $this->postJson("/api/v1/admin/shift-rotation/override", [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(200);

        // 5. Assert employee's relay has updated permanently to Relay B
        $this->employee->refresh();
        $this->assertEquals($relayB->id, $this->employee->relay_id);
    }

    public function test_override_is_blocked_when_target_shift_has_no_relay_mapped()
    {
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => now()->toDateString(),
        ]);

        // Shift B is deliberately left without a relay.
        $response = $this->postJson('/api/v1/admin/shift-rotation/override', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => "No relay is mapped to shift 'Night Shift'. Assign a relay to this shift in Relay Master first, then change the employee's shift.",
        ]);

        // Nothing is half-applied: the employee keeps their relay and their shift.
        $this->employee->refresh();
        $this->assertEquals($this->relayA->id, $this->employee->relay_id);

        $this->assertDatabaseMissing('employee_shift_overrides', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $this->assertDatabaseMissing('employee_shift_assignments', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);
    }
}
