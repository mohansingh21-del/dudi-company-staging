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

class ShiftChangeSwapEmployeesTest extends TestCase
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
            'employee_code' => 'EMP001',
            'name' => 'Ravi Verma',
            'joining_date' => '2026-01-01',
            'relay_shift' => 'relay_1',
            'is_active' => 1
        ]);

        $this->employee2 = Employee::create([
            'employee_code' => 'EMP002',
            'name' => 'Suman Sharma',
            'joining_date' => '2026-01-01',
            'relay_shift' => 'relay_2',
            'is_active' => 1
        ]);
    }

    public function test_can_swap_shifts_between_two_employees()
    {
        // Setup initial shift A assignment for employee 1
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-06-01',
        ]);

        // Setup initial shift B assignment for employee 2
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => '2026-06-01',
        ]);

        // Request shift swap
        $response = $this->postJson('/api/v1/admin/shift-rotation/swap', [
            'employee_id' => $this->employee1->id,
            'swap_with_employee_id' => $this->employee2->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 200,
            'message' => "Shifts swapped successfully. Ravi Verma is now on Night Shift and Suman Sharma is now on Morning Shift."
        ]);

        // Assert database has swapped shifts
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => now()->toDateString(),
        ]);

        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => now()->toDateString(),
        ]);

        // Assert history is created for employee 1 with the performing user's id
        $this->assertDatabaseHas('employee_shift_histories', [
            'employee_id' => $this->employee1->id,
            'old_shift_id' => $this->shiftA->id,
            'new_shift_id' => $this->shiftB->id,
            'user_id' => $this->adminUser->id,
        ]);

        // Assert history is created for employee 2 with the performing user's id
        $this->assertDatabaseHas('employee_shift_histories', [
            'employee_id' => $this->employee2->id,
            'old_shift_id' => $this->shiftB->id,
            'new_shift_id' => $this->shiftA->id,
            'user_id' => $this->adminUser->id,
        ]);
    }

    public function test_cannot_swap_shifts_if_employee_missing_assignment()
    {
        // Assign shift to employee 1, leave employee 2 unassigned
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-06-01',
        ]);

        $response = $this->postJson('/api/v1/admin/shift-rotation/swap', [
            'employee_id' => $this->employee1->id,
            'swap_with_employee_id' => $this->employee2->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 422,
            'message' => "Employee 'Suman Sharma' does not have a shift assignment."
        ]);
    }

    public function test_cannot_swap_shifts_if_both_have_same_shift()
    {
        // Setup initial shift A assignment for employee 1
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-06-01',
        ]);

        // Setup initial shift A assignment for employee 2
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-06-01',
        ]);

        // Request shift swap
        $response = $this->postJson('/api/v1/admin/shift-rotation/swap', [
            'employee_id' => $this->employee1->id,
            'swap_with_employee_id' => $this->employee2->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 422,
            'message' => "Both employees already have the same shift assigned."
        ]);
    }
}
