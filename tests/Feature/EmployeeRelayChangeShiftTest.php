<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftOverride;
use App\Models\Relay;
use App\Models\RelayShiftMapping;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftRosterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Moving an employee to another relay from Employee Management ends the shift
 * they held under the old relay. A non-rotating relay with no shift mapping has
 * no shift of its own, so the employee must list with none rather than keep
 * showing the old one.
 */
class EmployeeRelayChangeShiftTest extends TestCase
{
    use RefreshDatabase;

    protected $shiftA;
    protected $shiftB;
    protected $rotatingRelay;
    protected $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1,
        ]);

        $adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
        ]);
        $adminUser->roles()->attach($role);
        Sanctum::actingAs($adminUser);

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

        $this->rotatingRelay = Relay::create(['name' => 'Relay A', 'is_rotating' => true, 'is_active' => true]);
        $this->mapRelay($this->rotatingRelay, $this->shiftA);

        $this->employee = Employee::create([
            'employee_code' => 'EMP123',
            'name' => 'Neha Jain',
            'joining_date' => '2026-01-01',
            'relay_id' => $this->rotatingRelay->id,
            'is_active' => 1,
        ]);

        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => now()->subDays(10)->toDateString(),
        ]);
    }

    private function mapRelay(Relay $relay, Shift $shift)
    {
        $weekStart = now()->startOfWeek(\Carbon\Carbon::MONDAY);

        RelayShiftMapping::create([
            'week_start_date' => $weekStart->toDateString(),
            'week_end_date' => $weekStart->copy()->addDays(6)->toDateString(),
            'relay_id' => $relay->id,
            'shift_id' => $shift->id,
        ]);
    }

    private function changeRelay(?Relay $relay)
    {
        return $this->putJson('/api/v1/admin/employees/' . $this->employee->id, [
            'employee_code' => $this->employee->employee_code,
            'name' => $this->employee->name,
            'joining_date' => '01/01/2026',
            'relay_id' => $relay ? $relay->id : null,
        ]);
    }

    private function resolverShiftId(Employee $employee, string $dateStr)
    {
        $resolver = new ShiftRosterResolver();
        $employee->load('relay');
        $ctx = $resolver->preload(collect([$employee]), now()->subDays(30), now());

        return $resolver->resolve($employee, $dateStr, $ctx);
    }

    public function test_moving_to_non_rotating_relay_without_mapping_clears_the_shift()
    {
        $general = Relay::create(['name' => 'General', 'is_rotating' => false, 'is_active' => true]);

        $response = $this->changeRelay($general);

        $response->assertJsonPath('status', 200);
        $response->assertJsonPath('data.relay_id', $general->id);
        $response->assertJsonPath('data.shift_id', null);
        $response->assertJsonPath('data.shift', null);

        $employee = $this->employee->fresh();
        $today = now()->toDateString();

        $this->assertNull($employee->shift_id);
        $this->assertNull($employee->getShiftIdForDate($today));
        $this->assertNull($this->resolverShiftId($employee, $today));

        $list = $this->getJson('/api/v1/admin/employees/details?search=EMP123');
        $list->assertJsonPath('status', 200);
        $this->assertStringNotContainsString('Morning Shift', $list->getContent());
    }

    public function test_days_before_the_move_keep_the_old_shift()
    {
        $general = Relay::create(['name' => 'General', 'is_rotating' => false, 'is_active' => true]);

        $this->changeRelay($general)->assertJsonPath('status', 200);

        $employee = $this->employee->fresh();
        $earlier = now()->subDays(3)->toDateString();

        $this->assertEquals($this->shiftA->id, $employee->getShiftIdForDate($earlier));
        $this->assertEquals($this->shiftA->id, $this->resolverShiftId($employee, $earlier));
    }

    public function test_open_override_from_the_old_relay_is_ended()
    {
        EmployeeShiftOverride::create([
            'employee_id' => $this->employee->id,
            'effective_from' => now()->subDays(2)->toDateString(),
            'effective_until' => null,
            'shift_id' => $this->shiftB->id,
            'reason' => 'manual change',
        ]);
        $this->assertEquals($this->shiftB->id, $this->employee->fresh()->shift_id);

        $general = Relay::create(['name' => 'General', 'is_rotating' => false, 'is_active' => true]);
        $this->changeRelay($general)->assertJsonPath('status', 200);

        $this->assertNull($this->employee->fresh()->shift_id);
    }

    public function test_moving_to_another_rotating_relay_takes_that_relays_shift()
    {
        $relayB = Relay::create(['name' => 'Relay B', 'is_rotating' => true, 'is_active' => true]);
        $this->mapRelay($relayB, $this->shiftB);

        $response = $this->changeRelay($relayB);

        $response->assertJsonPath('status', 200);
        $response->assertJsonPath('data.shift_id', $this->shiftB->id);
    }

    public function test_update_without_relay_change_keeps_the_shift()
    {
        $response = $this->changeRelay($this->rotatingRelay);

        $response->assertJsonPath('status', 200);
        $response->assertJsonPath('data.shift_id', $this->shiftA->id);
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $this->employee->id,
            'to_date' => null,
        ]);
    }
}
