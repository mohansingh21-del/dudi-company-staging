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

class EmployeeShiftAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $employee1;
    protected $employee2;
    protected $shiftA;
    protected $shiftB;

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

        // Create employees
        $this->employee1 = Employee::create([
            'employee_code' => 'EMP123',
            'name' => 'Neha Jain',
            'joining_date' => '2026-01-01',
            'relay_shift' => 'relay_1',
            'is_active' => 1
        ]);

        $this->employee2 = Employee::create([
            'employee_code' => 'EMP456',
            'name' => 'Ramesh Kumar',
            'joining_date' => '2026-01-01',
            'relay_shift' => 'relay_2',
            'is_active' => 1
        ]);
    }

    public function test_can_assign_shift_to_new_employee()
    {
        $response = $this->postJson('/api/v1/admin/employee-shift-assignments', [
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Shift assigned successfully to employee'
        ]);

        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
        ]);
    }

    public function test_can_update_targeted_shift_for_existing_assignment()
    {
        // Setup initial assignment
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-05-01'
        ]);

        // Assign to a new targeted shift using the same API
        $response = $this->postJson('/api/v1/admin/employee-shift-assignments', [
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(200);

        // Verify the assignment was updated to shift B
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftB->id,
        ]);

        // Verify history is recorded
        $this->assertDatabaseHas('employee_shift_histories', [
            'employee_id' => $this->employee1->id,
            'old_shift_id' => $this->shiftA->id,
            'new_shift_id' => $this->shiftB->id,
        ]);
    }

    public function test_can_bulk_assign_or_update_shifts()
    {
        // Setup existing assignment for employee1
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-05-01'
        ]);

        // Request bulk assignment to Shift B for both employee1 (update) and employee2 (create)
        $response = $this->postJson('/api/v1/admin/employee-shift-assignments', [
            'employee_ids' => [$this->employee1->id, $this->employee2->id],
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Shift assigned successfully to employees'
        ]);

        // Verify employee1 updated to shift B
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftB->id,
        ]);

        // Verify employee2 created on shift B
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftB->id,
        ]);
    }

    public function test_cannot_assign_shift_to_general_shift_employee()
    {
        // Create general shift employee
        $generalEmployee = Employee::create([
            'employee_code' => 'EMP999',
            'name' => 'General Employee',
            'joining_date' => '2026-01-01',
            'relay_shift' => 'general',
            'is_active' => 1
        ]);

        $response = $this->postJson('/api/v1/admin/employee-shift-assignments', [
            'employee_id' => $generalEmployee->id,
            'shift_id' => $this->shiftA->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'status' => 422,
            'message' => "Shift assignment is not allowed for general shift employee 'General Employee'."
        ]);
    }
}
