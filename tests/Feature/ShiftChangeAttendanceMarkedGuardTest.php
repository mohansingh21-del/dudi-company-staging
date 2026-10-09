<?php

namespace Tests\Feature;

use App\Models\AttendanceProcessed;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Relay;
use App\Models\RelayShiftMapping;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BR: an employee whose attendance is already marked for today cannot have their
 * shift changed from the Shift Rotation module. The change takes effect from
 * today, so it would re-point a day attendance has already processed against the
 * old shift. Only a worked day (present / half day) blocks; absent, leave and
 * rest day do not.
 */
class ShiftChangeAttendanceMarkedGuardTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $employee;
    protected $shiftA;
    protected $shiftB;
    protected $relayA;
    protected $relayB;

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
        $this->relayB = Relay::create(['name' => 'Relay B', 'is_rotating' => true, 'is_active' => true]);

        $weekStart = now()->startOfWeek(\Carbon\Carbon::MONDAY);
        foreach ([[$this->relayA, $this->shiftA], [$this->relayB, $this->shiftB]] as [$relay, $shift]) {
            RelayShiftMapping::create([
                'week_start_date' => $weekStart->toDateString(),
                'week_end_date' => $weekStart->copy()->addDays(6)->toDateString(),
                'relay_id' => $relay->id,
                'shift_id' => $shift->id,
            ]);
        }

        $this->employee = $this->makeEmployee('EMP123', 'Neha Jain', $this->relayA, $this->shiftA);
    }

    private function makeEmployee($code, $name, Relay $relay, Shift $shift)
    {
        $employee = Employee::create([
            'employee_code' => $code,
            'name' => $name,
            'joining_date' => '2026-01-01',
            'relay_id' => $relay->id,
            'is_active' => 1,
        ]);

        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'from_date' => now()->toDateString(),
        ]);

        return $employee;
    }

    private function markAttendance(Employee $employee, Shift $shift, $date = null, $status = 'present')
    {
        return AttendanceProcessed::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'date' => $date ?: now()->toDateString(),
            'attendance_status' => $status,
            'working_hours' => 0,
            'late_minutes' => 0,
            'early_exit_minutes' => 0,
        ]);
    }

    private function assertShiftUnchanged(Employee $employee, Shift $shift, Relay $relay)
    {
        $this->assertDatabaseMissing('employee_shift_overrides', ['employee_id' => $employee->id]);
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
        ]);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'relay_id' => $relay->id]);
    }

    public function test_override_is_blocked_when_attendance_is_marked_today()
    {
        $this->markAttendance($this->employee, $this->shiftA);

        $response = $this->postJson('/api/v1/admin/shift-rotation/override', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('attendance marked for today', $response->json('message'));
        $this->assertStringContainsString('Morning Shift', $response->json('message'));
        $this->assertShiftUnchanged($this->employee, $this->shiftA, $this->relayA);
    }

    public function test_update_is_blocked_when_attendance_is_marked_today()
    {
        $this->markAttendance($this->employee, $this->shiftA);

        $response = $this->putJson('/api/v1/admin/shift-rotation/' . $this->employee->id, [
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(422);
        $this->assertShiftUnchanged($this->employee, $this->shiftA, $this->relayA);
    }

    public function test_bulk_change_is_blocked_when_any_selected_employee_has_attendance_today()
    {
        $other = $this->makeEmployee('EMP456', 'Ravi Kumar', $this->relayA, $this->shiftA);
        $this->markAttendance($this->employee, $this->shiftA);

        $response = $this->postJson('/api/v1/admin/shift-rotation', [
            'employee_ids' => [$this->employee->id, $other->id],
            'target_shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(422);
        $this->assertCount(1, $response->json('data.blocked'));
        $this->assertShiftUnchanged($this->employee, $this->shiftA, $this->relayA);
        $this->assertShiftUnchanged($other, $this->shiftA, $this->relayA);
    }

    public function test_swap_is_blocked_when_either_employee_has_attendance_today()
    {
        $other = $this->makeEmployee('EMP456', 'Ravi Kumar', $this->relayB, $this->shiftB);
        $this->markAttendance($other, $this->shiftB);

        $response = $this->postJson('/api/v1/admin/shift-rotation/swap', [
            'employee_id' => $this->employee->id,
            'swap_with_employee_id' => $other->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Ravi Kumar', $response->json('message'));
        $this->assertShiftUnchanged($this->employee, $this->shiftA, $this->relayA);
        $this->assertShiftUnchanged($other, $this->shiftB, $this->relayB);
    }

    public function test_override_is_blocked_when_marked_half_day_today()
    {
        $this->markAttendance($this->employee, $this->shiftA, null, 'half_day');

        $response = $this->postJson('/api/v1/admin/shift-rotation/override', [
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
        ]);

        $response->assertStatus(422);
        $this->assertShiftUnchanged($this->employee, $this->shiftA, $this->relayA);
    }

    public function test_override_is_allowed_when_today_is_not_a_worked_day()
    {
        foreach (['absent', 'leave', 'rest_day'] as $i => $status) {
            $employee = $this->makeEmployee("EMP90{$i}", "Worker {$i}", $this->relayA, $this->shiftA);
            $this->markAttendance($employee, $this->shiftA, null, $status);

            $response = $this->postJson('/api/v1/admin/shift-rotation/override', [
                'employee_id' => $employee->id,
                'shift_id' => $this->shiftB->id,
            ]);

            $response->assertStatus(200);
            $this->assertDatabaseHas('employee_shift_overrides', [
                'employee_id' => $employee->id,
                'shift_id' => $this->shiftB->id,
            ]);
        }
    }

    public function test_override_is_allowed_when_only_an_earlier_day_is_marked()
    {
        $this->markAttendance($this->employee, $this->shiftA, now()->subDay()->toDateString());

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
