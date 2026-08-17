<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RotateShiftsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotate_shifts_command_only_rotates_non_general_relay_shift_employees()
    {
        // Roster rotation sequence is defined as [1, 3, 2]
        $shift1 = new Shift([
            'shift_name' => 'Shift 1',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
        ]);
        $shift1->id = 1;
        $shift1->is_active = 1;
        $shift1->save();

        $shift3 = new Shift([
            'shift_name' => 'Shift 3',
            'start_time' => '16:00:00',
            'end_time' => '00:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
        ]);
        $shift3->id = 3;
        $shift3->is_active = 1;
        $shift3->save();

        $shift2 = new Shift([
            'shift_name' => 'Shift 2',
            'start_time' => '00:00:00',
            'end_time' => '08:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 1,
        ]);
        $shift2->id = 2;
        $shift2->is_active = 1;
        $shift2->save();

        // Create relays
        $relayGeneral = \App\Models\Relay::create(['name' => 'General', 'is_rotating' => false, 'is_active' => true]);
        $relayA = \App\Models\Relay::create(['name' => 'Relay A', 'is_rotating' => true, 'is_active' => true]);

        // Create an employee with non-rotating relay (should NOT be rotated)
        $employeeGeneral = Employee::create([
            'employee_code' => 'EMP001',
            'name' => 'General Employee',
            'joining_date' => '2026-01-01',
            'relay_id' => $relayGeneral->id,
            'is_active' => 1
        ]);

        EmployeeShiftAssignment::create([
            'employee_id' => $employeeGeneral->id,
            'shift_id' => $shift1->id,
            'from_date' => '2026-06-01'
        ]);

        // Create an employee with rotating relay (should be rotated)
        $employeeRelay = Employee::create([
            'employee_code' => 'EMP002',
            'name' => 'Relay Employee',
            'joining_date' => '2026-01-01',
            'relay_id' => $relayA->id,
            'is_active' => 1
        ]);

        EmployeeShiftAssignment::create([
            'employee_id' => $employeeRelay->id,
            'shift_id' => $shift1->id,
            'from_date' => '2026-06-01'
        ]);

        // Seed previous week mapping for Relay A to trigger rotation from shift 1 to 3
        $today = \Carbon\Carbon::today();
        $prevWeekStart = $today->copy()->subWeeks(1)->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
        $prevWeekEnd = $today->copy()->subWeeks(1)->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(6)->toDateString();

        \App\Models\RelayShiftMapping::create([
            'week_start_date' => $prevWeekStart,
            'week_end_date' => $prevWeekEnd,
            'relay_id' => $relayA->id,
            'shift_id' => $shift1->id,
        ]);

        // Run the rotation command
        $this->artisan('roster:rotate --force')
            ->assertExitCode(0);

        // General employee should still be in Shift 1
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employeeGeneral->id,
            'shift_id' => $shift1->id,
        ]);

        // Relay employee should be rotated to Shift 2 (next in descending active sequence [3, 1, 2])
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employeeRelay->id,
            'shift_id' => $shift2->id,
        ]);
    }

    public function test_manual_shift_rotation_only_list_and_rotate_non_general_relay_employees()
    {
        $role = \App\Models\Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $adminUser = \App\Models\User::create([
            'email' => 'admin_rot@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $adminUser->roles()->attach($role);

        \Laravel\Sanctum\Sanctum::actingAs($adminUser);

        $shift = Shift::create([
            'shift_name' => 'Shift Morning',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Create relays
        $relayGeneral = \App\Models\Relay::create(['name' => 'General', 'is_rotating' => false, 'is_active' => true]);
        $relayA = \App\Models\Relay::create(['name' => 'Relay A', 'is_rotating' => true, 'is_active' => true]);

        $employeeGeneral = Employee::create([
            'employee_code' => 'EMP003',
            'name' => 'General Employee 2',
            'joining_date' => '2026-01-01',
            'relay_id' => $relayGeneral->id,
            'is_active' => 1
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employeeGeneral->id,
            'shift_id' => $shift->id,
            'from_date' => '2026-06-01'
        ]);

        $employeeRelay = Employee::create([
            'employee_code' => 'EMP004',
            'name' => 'Relay Employee 2',
            'joining_date' => '2026-01-01',
            'relay_id' => $relayA->id,
            'is_active' => 1
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employeeRelay->id,
            'shift_id' => $shift->id,
            'from_date' => '2026-06-01'
        ]);

        // 1. Check index returns only Relay Employee 2
        $response = $this->getJson('/api/v1/admin/shift-rotation');
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'name' => 'Relay Employee 2'
        ]);
        $response->assertJsonMissing([
            'name' => 'General Employee 2'
        ]);

        // 2. Try to manually rotate shift for both. General employee should be ignored/skipped.
        $newShift = Shift::create([
            'shift_name' => 'Shift Evening',
            'start_time' => '16:00:00',
            'end_time' => '00:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        $response = $this->postJson('/api/v1/admin/shift-rotation', [
            'employee_ids' => [$employeeGeneral->id, $employeeRelay->id],
            'target_shift_id' => $newShift->id
        ]);

        $response->assertStatus(200);

        // General employee should NOT have been changed to Evening
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employeeGeneral->id,
            'shift_id' => $shift->id,
        ]);

        // Relay employee should have been changed to Evening
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employeeRelay->id,
            'shift_id' => $newShift->id,
        ]);
    }
}
