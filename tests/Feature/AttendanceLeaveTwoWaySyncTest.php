<?php

namespace Tests\Feature;

use App\Models\AttendanceProcessed;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceLeaveTwoWaySyncTest extends TestCase
{
    use RefreshDatabase;

    protected $employee;
    protected $leaveType;
    protected $admin;
    protected $site;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $admin = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $admin->roles()->attach($role);

        Sanctum::actingAs($admin);
        $this->admin = $admin;

        $workerRole = Role::create([
            'name' => 'Worker',
            'slug' => 'worker',
            'is_active' => 1
        ]);

        DB::table('designations')->insert([
            'id' => $workerRole->id,
            'designation_name' => 'Worker',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $site = Site::create([
            'site_name' => 'Coal Mine Alpha',
            'is_active' => 1
        ]);

        $this->site = $site;

        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Operations',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->employee = Employee::create([
            'employee_code' => 'EMP5001',
            'name' => 'Amit Sharma',
            'joining_date' => '2026-01-01',
            'is_active' => 1,
            'basic_salary' => 25000.00,
            'designation_id' => $workerRole->id,
            'department_id' => $departmentId,
            'site_id' => $site->id,
        ]);

        // The Form E blocks are seeded by the migrations; give Medical a quota.
        $this->leaveType = LeaveType::where('register_group', 'medical')->firstOrFail();
        $this->leaveType->update(['allowed_days' => 10, 'is_active' => true]);
    }

    private function markPresent(string $date): AttendanceProcessed
    {
        return AttendanceProcessed::create([
            'employee_id' => $this->employee->id,
            'date' => $date,
            'check_in' => $date . ' 09:00:00',
            'check_out' => $date . ' 18:00:00',
            'working_hours' => 9.0,
            'attendance_status' => 'present',
        ]);
    }

    private function fileLeave(string $from, string $to, string $status = 'approved'): Leave
    {
        return Leave::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'from_date' => $from,
            'to_date' => $to,
            'reason' => 'Fever',
            'status' => $status,
        ]);
    }

    private function statusOn(string $date): ?string
    {
        return AttendanceProcessed::where('employee_id', $this->employee->id)
            ->whereDate('date', $date)
            ->value('attendance_status');
    }

    public function test_approving_a_leave_turns_a_present_day_into_leave()
    {
        $this->markPresent('2026-06-10');
        $leave = $this->fileLeave('2026-06-10', '2026-06-10', 'pending');

        $this->assertSame('present', $this->statusOn('2026-06-10'));

        $this->postJson("/api/v1/admin/leaves/{$leave->id}/approve-reject", ['status' => 'approved'])
            ->assertJsonPath('status', 200);

        $this->assertSame('leave', $this->statusOn('2026-06-10'));

        $row = AttendanceProcessed::where('employee_id', $this->employee->id)->first();
        $this->assertNull($row->check_in);
        $this->assertNull($row->check_out);
        $this->assertEquals(0, $row->working_hours);
    }

    public function test_marking_a_present_day_as_leave_from_attendance_clears_the_punches()
    {
        $attendance = $this->markPresent('2026-06-10');

        $this->patchJson("/api/v1/admin/attendance/{$attendance->id}/status", [
            'attendance_status' => 'leave',
            'leave_type_id' => $this->leaveType->id,
        ])->assertJsonPath('status', 200);

        $row = $attendance->fresh();
        $this->assertSame('leave', $row->attendance_status);
        $this->assertNull($row->check_in);
        $this->assertNull($row->check_out);
        $this->assertEquals(0, $row->working_hours);

        $this->patchJson('/api/v1/admin/attendance/bulk-status', [
            'attendance_ids' => [$this->markPresent('2026-06-11')->id],
            'attendance_status' => 'leave',
            'leave_type_id' => $this->leaveType->id,
            'date' => '2026-06-11',
        ])->assertJsonPath('status', 200);

        $bulkRow = AttendanceProcessed::whereDate('date', '2026-06-11')->first();
        $this->assertSame('leave', $bulkRow->attendance_status);
        $this->assertNull($bulkRow->check_in);
    }

    public function test_leave_filed_already_approved_turns_a_present_day_into_leave()
    {
        $this->markPresent('2026-06-10');

        $this->postJson('/api/v1/admin/leaves', [
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'from_date' => '2026-06-10',
            'to_date' => '2026-06-10',
            'status' => 'approved',
        ])->assertJsonPath('status', 200);

        $this->assertSame('leave', $this->statusOn('2026-06-10'));
    }

    public function test_marking_present_cancels_a_single_day_leave()
    {
        $leave = $this->fileLeave('2026-06-10', '2026-06-10');
        $attendance = AttendanceProcessed::create([
            'employee_id' => $this->employee->id,
            'date' => '2026-06-10',
            'attendance_status' => 'leave',
        ]);

        $this->patchJson("/api/v1/admin/attendance/{$attendance->id}/status", [
            'attendance_status' => 'present',
        ])->assertJsonPath('status', 200);

        $this->assertSame('present', $this->statusOn('2026-06-10'));
        $this->assertSame('rejected', $leave->fresh()->status);
    }

    public function test_bulk_marking_present_cancels_a_pending_leave()
    {
        $leave = $this->fileLeave('2026-06-10', '2026-06-10', 'pending');

        $this->patchJson('/api/v1/admin/attendance/bulk-status', [
            'attendance_ids' => [$this->employee->id],
            'attendance_status' => 'present',
            'date' => '2026-06-10',
        ])->assertJsonPath('status', 200);

        $this->assertSame('present', $this->statusOn('2026-06-10'));
        $this->assertSame('rejected', $leave->fresh()->status);
    }

    public function test_marking_present_mid_range_only_takes_that_day_out_of_the_leave()
    {
        $leave = $this->fileLeave('2026-06-10', '2026-06-12');
        $attendance = AttendanceProcessed::create([
            'employee_id' => $this->employee->id,
            'date' => '2026-06-11',
            'attendance_status' => 'leave',
        ]);

        $this->patchJson("/api/v1/admin/attendance/{$attendance->id}/status", [
            'attendance_status' => 'present',
        ])->assertJsonPath('status', 200);

        $ranges = Leave::where('employee_id', $this->employee->id)
            ->where('status', 'approved')
            ->orderBy('from_date')
            ->get()
            ->map(fn ($l) => substr($l->from_date, 0, 10) . '..' . substr($l->to_date, 0, 10))
            ->all();

        $this->assertSame(['2026-06-10..2026-06-10', '2026-06-12..2026-06-12'], $ranges);
        $this->assertSame('present', $this->statusOn('2026-06-11'));
    }

    public function test_marking_absent_over_a_filed_leave_is_still_refused()
    {
        $leave = $this->fileLeave('2026-06-10', '2026-06-10');
        $attendance = AttendanceProcessed::create([
            'employee_id' => $this->employee->id,
            'date' => '2026-06-10',
            'attendance_status' => 'leave',
        ]);

        $this->patchJson("/api/v1/admin/attendance/{$attendance->id}/status", [
            'attendance_status' => 'absent',
        ])->assertStatus(422);

        $this->assertSame('approved', $leave->fresh()->status);
    }

    /** Put the employee on a shift plan's workforce for $date. */
    private function deployOn(string $date, string $status = 'active'): void
    {
        $shiftId = DB::table('shifts')->insertGetId([
            'shift_name' => 'Day Shift A',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $planId = DB::table('shift_plans')->insertGetId([
            'planning_date' => $date,
            'shift_id' => $shiftId,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->admin->id,
            'site_incharge_id' => $this->admin->id,
            'status' => 'active',
            'created_by' => $this->admin->id,
            'reference_no' => 'SP-' . $date,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('shift_workforce_deployments')->insert([
            'shift_plan_id' => $planId,
            'employee_id' => $this->employee->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    private function applyLeave(string $from, string $to)
    {
        return $this->postJson('/api/v1/admin/leaves', [
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'from_date' => $from,
            'to_date' => $to,
            'status' => 'approved',
        ]);
    }

    public function test_leave_is_refused_when_the_employee_is_present_and_deployed_on_a_shift()
    {
        $this->markPresent('2026-06-10');
        $this->deployOn('2026-06-10');

        $this->applyLeave('2026-06-09', '2026-06-11')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from_date');

        $this->assertSame(0, Leave::count());
        $this->assertSame('present', $this->statusOn('2026-06-10'));
    }

    public function test_approving_a_pending_leave_is_refused_once_the_employee_is_present_and_deployed()
    {
        $leave = $this->fileLeave('2026-06-10', '2026-06-10', 'pending');
        $this->markPresent('2026-06-10');
        $this->deployOn('2026-06-10');

        $this->postJson("/api/v1/admin/leaves/{$leave->id}/approve-reject", ['status' => 'approved'])
            ->assertStatus(422);

        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertSame('present', $this->statusOn('2026-06-10'));
    }

    public function test_marking_a_present_deployed_day_as_leave_from_attendance_is_refused()
    {
        $attendance = $this->markPresent('2026-06-10');
        $this->deployOn('2026-06-10');

        $this->patchJson("/api/v1/admin/attendance/{$attendance->id}/status", [
            'attendance_status' => 'leave',
            'leave_type_id' => $this->leaveType->id,
        ])->assertStatus(422);

        $this->assertSame('present', $this->statusOn('2026-06-10'));
    }

    public function test_leave_is_allowed_when_deployed_but_not_present_or_removed_from_the_shift()
    {
        // Deployed, attendance not marked.
        $this->deployOn('2026-06-10');
        $this->applyLeave('2026-06-10', '2026-06-10')->assertJsonPath('status', 200);

        // Present, but the deployment was removed.
        $this->markPresent('2026-06-15');
        $this->deployOn('2026-06-15', 'removed');
        $this->applyLeave('2026-06-15', '2026-06-15')->assertJsonPath('status', 200);

        $this->assertSame('leave', $this->statusOn('2026-06-15'));
    }
}
