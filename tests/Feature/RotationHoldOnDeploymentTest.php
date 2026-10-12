<?php

namespace Tests\Feature;

use App\Console\Commands\RotateShiftsCommand;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Relay;
use App\Models\RelayShiftMapping;
use App\Models\Shift;
use App\Models\ShiftPlan;
use App\Models\ShiftWorkforceDeployment;
use App\Models\Site;
use App\Models\User;
use App\Services\RotationHoldService;
use App\Services\WorkforceDeploymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BR: an employee deployed on an open shift plan after roster:rotate has already
 * run for the coming week must still be held on that plan's shift. They keep it
 * for the whole week even if the plan is closed mid-week, and rejoin their relay
 * on the next rotation.
 */
class RotationHoldOnDeploymentTest extends TestCase
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

        // Sunday morning, when the scheduler rotates for the week starting 2026-10-12.
        Carbon::setTestNow('2026-10-11 05:30:00');

        $this->user = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
        ]);
        $this->actingAs($this->user);

        $this->site = Site::create(['site_name' => 'Site Alpha', 'is_active' => true]);

        // Descending start time gives the sequence Evening -> Morning -> Night.
        $this->shiftMorning = $this->makeShift('Morning', '08:00:00', '16:00:00');
        $this->shiftEvening = $this->makeShift('Evening', '16:00:00', '00:00:00');
        $this->shiftNight = $this->makeShift('Night', '00:00:00', '08:00:00');

        $this->relay = Relay::create(['name' => 'Relay A', 'is_rotating' => true, 'is_active' => true]);

        RelayShiftMapping::create([
            'week_start_date' => '2026-10-05',
            'week_end_date' => '2026-10-11',
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
            'from_date' => '2026-10-05',
        ]);

        return $employee;
    }

    private function makePlan()
    {
        return ShiftPlan::create([
            'planning_date' => '2026-10-11',
            'shift_id' => $this->shiftMorning->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1500.00,
            'supervisor_id' => $this->user->id,
            'site_incharge_id' => $this->user->id,
            'status' => 'in_progress',
            'created_by' => $this->user->id,
            'reference_no' => 'SP-OPEN',
        ]);
    }

    /**
     * Rotate on Sunday morning, then create the plan and deploy that evening.
     */
    private function rotateThenDeploy(Employee $employee)
    {
        $this->artisan('roster:rotate')->assertExitCode(0);
        $this->assertEquals($this->shiftNight->id, $employee->fresh()->getShiftIdForDate('2026-10-12'));

        Carbon::setTestNow('2026-10-11 21:30:00');
        $plan = $this->makePlan();
        app(WorkforceDeploymentService::class)->loadRelayWorkforce($plan->id);

        $this->assertDatabaseHas('shift_workforce_deployments', [
            'shift_plan_id' => $plan->id,
            'employee_id' => $employee->id,
            'status' => 'active',
        ]);

        return $plan;
    }

    public function test_deploying_after_the_rotation_run_holds_the_employee_for_the_new_week()
    {
        $employee = $this->makeEmployee('EMP001');
        $free = $this->makeEmployee('EMP002');
        // EMP002 is absent that day, so is never deployed and rotates with the relay.
        \App\Models\AttendanceProcessed::create([
            'employee_id' => $free->id,
            'date' => '2026-10-11',
            'attendance_status' => 'absent',
        ]);

        $this->rotateThenDeploy($employee);

        $this->assertEquals($this->shiftMorning->id, $employee->fresh()->getShiftIdForDate('2026-10-12'));
        $this->assertEquals($this->shiftNight->id, $free->fresh()->getShiftIdForDate('2026-10-12'));
        $this->assertDatabaseHas('employee_shift_overrides', [
            'employee_id' => $employee->id,
            'shift_id' => $this->shiftMorning->id,
            'reason' => RotateShiftsCommand::HOLD_REASON,
        ]);
    }

    public function test_hold_survives_a_mid_week_close_and_ends_on_the_next_rotation()
    {
        $employee = $this->makeEmployee('EMP001');
        $plan = $this->rotateThenDeploy($employee);

        // Plan is closed on Wednesday of the held week.
        Carbon::setTestNow('2026-10-14 10:00:00');
        $plan->update(['status' => 'completed']);
        app(RotationHoldService::class)->releaseUnstartedHolds([$employee->id]);

        $this->assertEquals($this->shiftMorning->id, $employee->fresh()->shift_id);
        $this->assertEquals($this->shiftMorning->id, $employee->fresh()->getShiftIdForDate('2026-10-17'));

        // Next Sunday's run puts them back on their relay's shift.
        Carbon::setTestNow('2026-10-18 05:30:00');
        $this->artisan('roster:rotate')->assertExitCode(0);

        $relayShiftId = RelayShiftMapping::getForDate($this->relay->id, '2026-10-19')->shift_id;
        $this->assertNotEquals($this->shiftMorning->id, $relayShiftId);
        $this->assertEquals($relayShiftId, $employee->fresh()->getShiftIdForDate('2026-10-19'));
        // The week already worked still reads as the held shift.
        $this->assertEquals($this->shiftMorning->id, $employee->fresh()->getShiftIdForDate('2026-10-15'));
    }

    public function test_hold_is_dropped_when_the_employee_is_removed_before_the_week_starts()
    {
        $employee = $this->makeEmployee('EMP001');
        $plan = $this->rotateThenDeploy($employee);

        $deployment = ShiftWorkforceDeployment::where('shift_plan_id', $plan->id)
            ->where('employee_id', $employee->id)
            ->first();
        app(WorkforceDeploymentService::class)->removeDeployment($deployment->id, 'Deployed by mistake');

        $this->assertEquals($this->shiftNight->id, $employee->fresh()->getShiftIdForDate('2026-10-12'));
        $this->assertDatabaseMissing('employee_shift_overrides', ['employee_id' => $employee->id]);
    }

    public function test_deploying_before_the_rotation_run_writes_no_hold_of_its_own()
    {
        $employee = $this->makeEmployee('EMP001');

        $plan = $this->makePlan();
        app(WorkforceDeploymentService::class)->loadRelayWorkforce($plan->id);

        $this->assertDatabaseMissing('employee_shift_overrides', ['employee_id' => $employee->id]);

        // The command itself still holds them when it runs.
        $this->artisan('roster:rotate')->assertExitCode(0);
        $this->assertEquals($this->shiftMorning->id, $employee->fresh()->getShiftIdForDate('2026-10-12'));
    }
}
