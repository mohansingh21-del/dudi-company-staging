<?php

namespace Tests\Feature;

use App\Console\Commands\RotateShiftsCommand;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftOverride;
use App\Models\Relay;
use App\Models\RelayShiftMapping;
use App\Models\Shift;
use App\Models\ShiftPlan;
use App\Models\ShiftWorkforceDeployment;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR: roster:rotate must not move an employee who is still actively deployed on a
 * shift plan that has not been closed. The rest of their relay rotates; they stay
 * on their current shift until the plan is closed.
 */
class RotateShiftsHoldOpenPlanTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $site;
    protected $relay;
    protected $shiftMorning;
    protected $shiftEvening;
    protected $shiftNight;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday, so the rotation targets the current week starting 2026-09-21.
        Carbon::setTestNow('2026-09-23 10:00:00');

        $this->user = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
        ]);

        $this->site = Site::create(['site_name' => 'Site Alpha', 'is_active' => true]);

        // Descending start time gives the sequence Evening -> Morning -> Night.
        $this->shiftMorning = $this->makeShift('Morning', '08:00:00', '16:00:00');
        $this->shiftEvening = $this->makeShift('Evening', '16:00:00', '00:00:00');
        $this->shiftNight = $this->makeShift('Night', '00:00:00', '08:00:00');

        $this->relay = Relay::create(['name' => 'Relay A', 'is_rotating' => true, 'is_active' => true]);

        RelayShiftMapping::create([
            'week_start_date' => '2026-09-14',
            'week_end_date' => '2026-09-20',
            'relay_id' => $this->relay->id,
            'shift_id' => $this->shiftMorning->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeShift($name, $start, $end)
    {
        return Shift::create([
            'shift_name' => $name,
            'start_time' => $start,
            'end_time' => $end,
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1,
        ]);
    }

    private function makeEmployee($code)
    {
        $employee = Employee::create([
            'employee_code' => $code,
            'name' => "Employee {$code}",
            'joining_date' => '2026-01-01',
            'relay_id' => $this->relay->id,
            'is_active' => 1,
        ]);

        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $this->shiftMorning->id,
            'from_date' => '2026-09-14',
        ]);

        return $employee;
    }

    private function deployOnPlan(Employee $employee, $status, $reference)
    {
        $plan = ShiftPlan::create([
            'planning_date' => '2026-09-20',
            'shift_id' => $this->shiftMorning->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1500.00,
            'supervisor_id' => $this->user->id,
            'site_incharge_id' => $this->user->id,
            'status' => $status,
            'created_by' => $this->user->id,
            'reference_no' => $reference,
        ]);

        ShiftWorkforceDeployment::create([
            'shift_plan_id' => $plan->id,
            'employee_id' => $employee->id,
            'relay_id' => $employee->relay_id,
            'is_borrowed' => false,
            'deployed_by' => $this->user->id,
            'status' => 'active',
        ]);

        return $plan;
    }

    public function test_employee_on_open_plan_keeps_shift_while_relay_rotates()
    {
        $held = $this->makeEmployee('EMP001');
        $free = $this->makeEmployee('EMP002');
        $this->deployOnPlan($held, 'in_progress', 'SP-OPEN');

        $this->artisan('roster:rotate')->assertExitCode(0);

        // The relay itself still rotates.
        $this->assertDatabaseHas('relay_shift_mappings', [
            'week_start_date' => '2026-09-21',
            'relay_id' => $this->relay->id,
            'shift_id' => $this->shiftNight->id,
        ]);

        $this->assertEquals($this->shiftNight->id, $free->fresh()->getShiftIdForDate('2026-09-22'));
        $this->assertEquals($this->shiftMorning->id, $held->fresh()->getShiftIdForDate('2026-09-22'));

        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $held->id,
            'shift_id' => $this->shiftMorning->id,
        ]);
        $this->assertDatabaseHas('employee_shift_overrides', [
            'employee_id' => $held->id,
            'shift_id' => $this->shiftMorning->id,
            'reason' => RotateShiftsCommand::HOLD_REASON,
        ]);
    }

    public function test_employee_on_closed_plan_rotates_normally()
    {
        $employee = $this->makeEmployee('EMP001');
        $this->deployOnPlan($employee, 'completed', 'SP-DONE');

        $this->artisan('roster:rotate')->assertExitCode(0);

        $this->assertEquals($this->shiftNight->id, $employee->fresh()->getShiftIdForDate('2026-09-22'));
        $this->assertDatabaseMissing('employee_shift_overrides', ['employee_id' => $employee->id]);
    }

    public function test_forced_rerun_does_not_duplicate_the_hold()
    {
        $held = $this->makeEmployee('EMP001');
        $this->deployOnPlan($held, 'in_progress', 'SP-OPEN');

        $this->artisan('roster:rotate')->assertExitCode(0);
        $this->artisan('roster:rotate --force')->assertExitCode(0);

        $this->assertEquals(1, EmployeeShiftOverride::where('employee_id', $held->id)->count());
        $this->assertEquals($this->shiftMorning->id, $held->fresh()->getShiftIdForDate('2026-09-22'));
    }

    public function test_mid_week_forced_run_holds_employee_on_the_open_plans_shift()
    {
        // The Sunday run already moved the relay Morning -> Night for this week.
        RelayShiftMapping::create([
            'week_start_date' => '2026-09-21',
            'week_end_date' => '2026-09-27',
            'relay_id' => $this->relay->id,
            'shift_id' => $this->shiftNight->id,
        ]);

        $held = $this->makeEmployee('EMP001');
        $plan = $this->deployOnPlan($held, 'in_progress', 'SP-OPEN');
        $plan->update(['planning_date' => '2026-09-22', 'shift_id' => $this->shiftNight->id]);

        // A hold left behind by an earlier run on the wrong shift is corrected.
        EmployeeShiftOverride::create([
            'employee_id' => $held->id,
            'effective_from' => '2026-09-21',
            'shift_id' => $this->shiftMorning->id,
            'reason' => RotateShiftsCommand::HOLD_REASON,
        ]);

        $this->artisan('roster:rotate --force')->assertExitCode(0);

        $this->assertEquals($this->shiftNight->id, $held->fresh()->getShiftIdForDate('2026-09-23'));
        $this->assertEquals($this->shiftNight->id, $held->fresh()->shift_id);
    }

    public function test_held_employee_rejoins_relay_after_plan_is_closed()
    {
        $held = $this->makeEmployee('EMP001');
        $plan = $this->deployOnPlan($held, 'in_progress', 'SP-OPEN');

        $this->artisan('roster:rotate')->assertExitCode(0);

        $plan->update(['status' => 'completed']);
        Carbon::setTestNow('2026-09-30 10:00:00');

        $this->artisan('roster:rotate')->assertExitCode(0);

        // Relay moved Night -> Evening; the employee follows it again.
        $this->assertEquals($this->shiftEvening->id, $held->fresh()->getShiftIdForDate('2026-09-29'));
    }
}
