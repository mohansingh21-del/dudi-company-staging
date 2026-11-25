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

class ShiftRotationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $relayShift1;
    protected $relayShift2;
    protected $generalEmployee;
    protected $relayEmployee1;
    protected $relayEmployee2;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create role and user for authentication
        $role = Role::create([
            'name' => 'Super Admin',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);

        $this->adminUser->roles()->attach($role->id);

        // 2. Create shifts
        $this->relayShift1 = Shift::create([
            'shift_name' => 'Relay 1 Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->relayShift2 = Shift::create([
            'shift_name' => 'Relay 2 Shift',
            'start_time' => '16:00:00',
            'end_time' => '00:00:00',
        ]);

        // Create relays
        $relayGeneral = \App\Models\Relay::create(['name' => 'General', 'is_rotating' => false, 'is_active' => true]);
        $relayA = \App\Models\Relay::create(['name' => 'Relay A', 'is_rotating' => true, 'is_active' => true]);
        $relayB = \App\Models\Relay::create(['name' => 'Relay B', 'is_rotating' => true, 'is_active' => true]);

        // Each rotating relay owns one shift for the week. Moving an employee between
        // shifts moves them between these relays, so both shifts need an owner.
        $weekStart = now()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
        $weekEnd = now()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(6)->toDateString();

        \App\Models\RelayShiftMapping::create([
            'week_start_date' => $weekStart,
            'week_end_date' => $weekEnd,
            'relay_id' => $relayA->id,
            'shift_id' => $this->relayShift1->id,
        ]);

        \App\Models\RelayShiftMapping::create([
            'week_start_date' => $weekStart,
            'week_end_date' => $weekEnd,
            'relay_id' => $relayB->id,
            'shift_id' => $this->relayShift2->id,
        ]);

        // 3. Create employees
        $this->generalEmployee = Employee::create([
            'employee_code' => 'EMPGEN01',
            'name' => 'General Employee',
            'joining_date' => now()->toDateString(),
            'relay_id' => $relayGeneral->id,
            'is_active' => 1,
        ]);

        $this->relayEmployee1 = Employee::create([
            'employee_code' => 'EMPRELAY01',
            'name' => 'Relay Employee 1',
            'joining_date' => now()->toDateString(),
            'relay_id' => $relayA->id,
            'is_active' => 1,
        ]);

        $this->relayEmployee2 = Employee::create([
            'employee_code' => 'EMPRELAY02',
            'name' => 'Relay Employee 2',
            'joining_date' => now()->toDateString(),
            'relay_id' => $relayB->id,
            'is_active' => 1,
        ]);

        // 4. Create base shift assignments
        EmployeeShiftAssignment::create([
            'employee_id' => $this->generalEmployee->id,
            'shift_id' => $this->relayShift1->id,
            'from_date' => now()->toDateString(),
        ]);

        EmployeeShiftAssignment::create([
            'employee_id' => $this->relayEmployee1->id,
            'shift_id' => $this->relayShift1->id,
            'from_date' => now()->toDateString(),
        ]);

        EmployeeShiftAssignment::create([
            'employee_id' => $this->relayEmployee2->id,
            'shift_id' => $this->relayShift2->id,
            'from_date' => now()->toDateString(),
        ]);
    }

    public function test_index_only_lists_relay_employees()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/admin/shift-rotation');

        $response->assertStatus(200);

        // Check that relay employees are present, general employee is not.
        $data = $response->json('data');
        $this->assertCount(2, $data);

        $names = collect($data)->pluck('name')->toArray();
        $this->assertContains('Relay Employee 1', $names);
        $this->assertContains('Relay Employee 2', $names);
        $this->assertNotContains('General Employee', $names);
    }

    public function test_store_filters_out_general_employees_and_fails_if_only_general_provided()
    {
        Sanctum::actingAs($this->adminUser);

        // Try with only general employee
        $response = $this->postJson('/api/v1/admin/shift-rotation', [
            'employee_ids' => [$this->generalEmployee->id],
            'target_shift_id' => $this->relayShift2->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'No active relay employees selected for shift rotation.'
        ]);

        // Try with both relay and general employee
        $response = $this->postJson('/api/v1/admin/shift-rotation', [
            'employee_ids' => [$this->relayEmployee1->id, $this->generalEmployee->id],
            'target_shift_id' => $this->relayShift2->id,
        ]);

        $response->assertStatus(200);
        // Relay employee 1 should be rotated to Relay 2 Shift
        $this->assertEquals(
            $this->relayShift2->id,
            EmployeeShiftAssignment::where('employee_id', $this->relayEmployee1->id)->latest()->first()->shift_id
        );
        // General employee should remain on Relay 1 Shift
        $this->assertEquals(
            $this->relayShift1->id,
            EmployeeShiftAssignment::where('employee_id', $this->generalEmployee->id)->latest()->first()->shift_id
        );
    }

    public function test_update_blocks_general_employee_but_allows_relay_employee()
    {
        Sanctum::actingAs($this->adminUser);

        // General employee update should fail
        $response = $this->putJson("/api/v1/admin/shift-rotation/{$this->generalEmployee->id}", [
            'shift_id' => $this->relayShift2->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Shift rotation is not allowed for non-rotating/general shift employees.'
        ]);

        // Relay employee update should succeed
        $response = $this->putJson("/api/v1/admin/shift-rotation/{$this->relayEmployee1->id}", [
            'shift_id' => $this->relayShift2->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(
            $this->relayShift2->id,
            EmployeeShiftAssignment::where('employee_id', $this->relayEmployee1->id)->latest()->first()->shift_id
        );
    }

    public function test_override_blocks_general_employee_but_allows_relay_employee()
    {
        Sanctum::actingAs($this->adminUser);

        // General employee override should fail
        $response = $this->postJson('/api/v1/admin/shift-rotation/override', [
            'employee_id' => $this->generalEmployee->id,
            'shift_id' => $this->relayShift2->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Shift rotation/override is not allowed for non-rotating/general shift employees.'
        ]);

        // Relay employee override should succeed
        $response = $this->postJson('/api/v1/admin/shift-rotation/override', [
            'employee_id' => $this->relayEmployee1->id,
            'shift_id' => $this->relayShift2->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(
            $this->relayShift2->id,
            EmployeeShiftAssignment::where('employee_id', $this->relayEmployee1->id)->latest()->first()->shift_id
        );
    }

    public function test_swap_blocks_general_employees()
    {
        Sanctum::actingAs($this->adminUser);

        // Swap with a general employee should fail
        $response = $this->postJson('/api/v1/admin/shift-rotation/swap', [
            'employee_id' => $this->relayEmployee1->id,
            'swap_with_employee_id' => $this->generalEmployee->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Shift swap is not allowed for non-rotating/general shift employees.'
        ]);

        // Swap two relay employees should succeed
        $response = $this->postJson('/api/v1/admin/shift-rotation/swap', [
            'employee_id' => $this->relayEmployee1->id,
            'swap_with_employee_id' => $this->relayEmployee2->id,
        ]);

        $response->assertStatus(200);
        
        // Check swapped shifts
        $this->assertEquals(
            $this->relayShift2->id,
            EmployeeShiftAssignment::where('employee_id', $this->relayEmployee1->id)->latest()->first()->shift_id
        );
        $this->assertEquals(
            $this->relayShift1->id,
            EmployeeShiftAssignment::where('employee_id', $this->relayEmployee2->id)->latest()->first()->shift_id
        );
    }
}
