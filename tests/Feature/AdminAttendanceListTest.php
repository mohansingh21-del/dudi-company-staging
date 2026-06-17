<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Site;
use App\Models\Shift;
use App\Models\AttendanceProcessed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class AdminAttendanceListTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $employee1;
    protected $employee2;
    protected $site;
    protected $shift;
    protected $departmentId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin role and user
        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($role);

        // Authenticate
        Sanctum::actingAs($this->adminUser);

        // Create designation role
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

        // Create Site
        $this->site = Site::create([
            'site_name' => 'Coal Mine Alpha',
            'is_active' => 1
        ]);

        // Create Department
        $this->departmentId = DB::table('departments')->insertGetId([
            'name' => 'Operations',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Create Shift
        $shiftId = DB::table('shifts')->insertGetId([
            'shift_name' => 'Day Shift A',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        $this->shift = Shift::find($shiftId);

        // Create Employee 1
        $this->employee1 = Employee::create([
            'employee_code' => 'EMP5001',
            'name' => 'Amit Sharma',
            'joining_date' => '2026-01-01',
            'is_active' => 1,
            'basic_salary' => 25000.00,
            'designation_id' => $workerRole->id,
            'department_id' => $this->departmentId,
            'site_id' => $this->site->id,
        ]);

        // Create Employee 2
        $this->employee2 = Employee::create([
            'employee_code' => 'EMP5002',
            'name' => 'Ravi Verma',
            'joining_date' => '2026-01-01',
            'is_active' => 1,
            'basic_salary' => 22000.00,
            'designation_id' => $workerRole->id,
            'department_id' => $this->departmentId,
            'site_id' => $this->site->id,
        ]);
    }

    public function test_can_get_daily_attendance_list_and_stats()
    {
        // Setup processed attendance records for today (2026-06-01)
        AttendanceProcessed::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-01',
            'check_in' => '2026-06-01 09:00:00',
            'check_out' => '2026-06-01 18:00:00',
            'working_hours' => 9.0,
            'attendance_status' => 'present'
        ]);

        AttendanceProcessed::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-01',
            'check_in' => '2026-06-01 09:10:00',
            'check_out' => '2026-06-01 15:00:00',
            'working_hours' => 5.83,
            'attendance_status' => 'half_day'
        ]);

        $response = $this->getJson('/api/v1/admin/attendance?date=2026-06-01&view_type=daily');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'summary' => [
                    'total_employees',
                    'present',
                    'absent',
                    'half_day',
                    'leaves',
                ],
                'data' => [
                    '*' => [
                        'id',
                        'employee_name',
                        'employee_code',
                        'site_name',
                        'shift_name',
                        'date',
                        'check_in',
                        'check_out',
                        'working_hours',
                        'attendance_status',
                        'attendance_status_label',
                    ]
                ],
                'pagination'
            ]);

        $response->assertJsonFragment([
            'total_employees' => 2,
            'present' => 1,
            'absent' => 0,
            'half_day' => 1,
            'leaves' => 0,
        ]);

        $response->assertJsonFragment([
            'employee_name' => 'Amit Sharma',
            'employee_code' => 'EMP5001',
            'site_name' => 'Coal Mine Alpha',
            'shift_name' => 'Day Shift A',
            'date' => '01 Jun 2026',
            'check_in' => '09:00',
            'check_out' => '18:00',
            'working_hours' => '9.00',
            'attendance_status' => 'present',
            'attendance_status_label' => 'Present',
        ]);
    }

    public function test_can_get_monthly_attendance_list_and_stats()
    {
        $this->employee1->update(['rest_days' => 4]);
        $this->employee2->update(['rest_days' => 5]);

        // Setup processed attendance records for multiple days in June 2026
        AttendanceProcessed::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-01',
            'check_in' => '2026-06-01 09:00:00',
            'check_out' => '2026-06-01 18:00:00',
            'working_hours' => 9.0,
            'attendance_status' => 'present'
        ]);

        AttendanceProcessed::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-02',
            'check_in' => '2026-06-02 09:00:00',
            'check_out' => '2026-06-02 18:00:00',
            'working_hours' => 9.0,
            'attendance_status' => 'leave'
        ]);

        // Add a rest day for employee 1
        AttendanceProcessed::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-03',
            'attendance_status' => 'rest_day'
        ]);

        AttendanceProcessed::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-05',
            'check_in' => '2026-06-05 09:00:00',
            'check_out' => '2026-06-05 18:00:00',
            'working_hours' => 9.0,
            'attendance_status' => 'absent'
        ]);

        // Hit monthly API for June 2026, passing June 1st
        $response = $this->getJson('/api/v1/admin/attendance?date=2026-06-01&view_type=monthly');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'total_employees' => 2,
                'present' => 1,
                'absent' => 1,
                'half_day' => 0,
                'leaves' => 1,
            ]);

        // Check if both employees are listed (since it returns 1 row per employee in monthly view)
        $this->assertCount(2, $response->json('data'));

        // Assert formatted rest_day values
        $emp1Data = collect($response->json('data'))->firstWhere('employee_id', $this->employee1->id);
        $emp2Data = collect($response->json('data'))->firstWhere('employee_id', $this->employee2->id);

        $this->assertNotNull($emp1Data);
        $this->assertNotNull($emp2Data);
        $this->assertEquals('1/4', $emp1Data['rest_day']);
        $this->assertEquals('0/5', $emp2Data['rest_day']);
    }

    public function test_can_filter_attendance_list_by_search_site_and_department()
    {
        // Setup processed attendance records
        AttendanceProcessed::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-01',
            'check_in' => '2026-06-01 09:00:00',
            'check_out' => '2026-06-01 18:00:00',
            'working_hours' => 9.0,
            'attendance_status' => 'present'
        ]);

        AttendanceProcessed::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-01',
            'check_in' => '2026-06-01 09:10:00',
            'check_out' => '2026-06-01 18:00:00',
            'working_hours' => 8.83,
            'attendance_status' => 'present'
        ]);

        // Filter by Search (Amit)
        $response = $this->getJson('/api/v1/admin/attendance?date=2026-06-01&search=Amit');
        $response->assertStatus(200)
            ->assertJsonFragment([
                'total_employees' => 1,
                'present' => 1,
            ]);
        $this->assertCount(1, $response->json('data'));

        // Filter by Site
        $response = $this->getJson("/api/v1/admin/attendance?date=2026-06-01&site_id={$this->site->id}");
        $response->assertStatus(200)
            ->assertJsonFragment([
                'total_employees' => 2,
                'present' => 2,
            ]);

        // Filter by invalid department
        $response = $this->getJson("/api/v1/admin/attendance?date=2026-06-01&department_id=9999");
        $response->assertStatus(200)
            ->assertJsonFragment([
                'total_employees' => 0,
                'present' => 0,
            ]);
    }

    public function test_can_filter_monthly_attendance_by_attendance_status_and_employee_status()
    {
        // Setup processed attendance records for multiple days in June 2026
        AttendanceProcessed::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-01',
            'check_in' => '2026-06-01 09:00:00',
            'check_out' => '2026-06-01 18:00:00',
            'working_hours' => 9.0,
            'attendance_status' => 'present'
        ]);

        AttendanceProcessed::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-02',
            'check_in' => '2026-06-02 09:00:00',
            'check_out' => '2026-06-02 18:00:00',
            'working_hours' => 9.0,
            'attendance_status' => 'leave'
        ]);

        AttendanceProcessed::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-05',
            'check_in' => '2026-06-05 09:00:00',
            'check_out' => '2026-06-05 18:00:00',
            'working_hours' => 9.0,
            'attendance_status' => 'absent'
        ]);

        // Filter using month and year parameters instead of date, and filter by attendance_status = absent
        $response = $this->getJson('/api/v1/admin/attendance?month=6&year=2026&view_type=monthly&attendance_status=absent');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->employee2->id, $response->json('data.0.employee_id'));

        // Filter using status parameter alias (status = present)
        $response = $this->getJson('/api/v1/admin/attendance?month=6&year=2026&view_type=monthly&status=present');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->employee1->id, $response->json('data.0.employee_id'));

        // Make employee2 inactive and test employee_status = inactive filter
        $this->employee2->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/admin/attendance?month=6&year=2026&view_type=monthly&employee_status=inactive');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->employee2->id, $response->json('data.0.employee_id'));

        // Test employee_status = all
        $response = $this->getJson('/api/v1/admin/attendance?month=6&year=2026&view_type=monthly&employee_status=all');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_get_employee_attendance_details()
    {
        // 1. Create processed attendance for employee 1
        $record = AttendanceProcessed::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-01',
            'check_in' => '2026-06-01 09:15:00',
            'check_out' => '2026-06-01 18:30:00',
            'working_hours' => 9.25,
            'attendance_status' => 'present'
        ]);

        // 2. Create an approved leave for employee 1 on 2026-06-10
        $leaveType = \App\Models\LeaveType::create([
            'name' => 'Sick Leave',
            'leave_category' => 'paid',
            'allowed_days' => 10,
            'is_active' => true,
        ]);

        \App\Models\Leave::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => '2026-06-10',
            'to_date' => '2026-06-10',
            'reason' => 'Fever',
            'status' => 'approved',
        ]);

        // 3. Create a Holiday on 2026-06-15
        \App\Models\Holiday::create([
            'holiday_name' => 'Independence Day',
            'holiday_date' => '2026-06-15',
            'site_id' => $this->employee1->site_id,
            'holiday_type' => 'Public Holiday',
            'is_active' => true,
        ]);

        // Hit the details endpoint for June 2026
        $response = $this->getJson("/api/v1/admin/attendance/employee/{$this->employee1->id}?month=6&year=2026");

        $response->assertStatus(200)
            ->assertJsonPath('data.employee.name', $this->employee1->name)
            ->assertJsonPath('data.employee.employee_code', $this->employee1->employee_code)
            ->assertJsonPath('data.month', 'June 2026');

        $history = $response->json('data.history');

        // June 2026 has 30 days
        $this->assertCount(30, $history);

        // Day 1: Present (based on AttendanceProcessed)
        $this->assertEquals('2026-06-01', $history[0]['date']);
        $this->assertEquals('Present', $history[0]['status']);
        $this->assertEquals('09:15', $history[0]['check_in']);
        $this->assertEquals('18:30', $history[0]['check_out']);
        $this->assertEquals('09:15', $history[0]['duration']);
        $this->assertEquals('9h 15m', $history[0]['duration_label']);
        $this->assertEquals($record->id, $history[0]['attendance_processed_id']);

        // Day 6 (June 6th, 2026) is Saturday -> Weekend
        $this->assertEquals('Weekend', $history[5]['status']);

        // Day 10 (June 10th, 2026) -> Leave
        $this->assertEquals('Leave', $history[9]['status']);

        // Day 15 (June 15th, 2026) -> Holiday
        $this->assertEquals('Holiday', $history[14]['status']);
    }

    public function test_can_correct_attendance()
    {
        // 1. Create a second site to test site update
        $anotherSite = \App\Models\Site::create([
            'site_name' => 'Coal Mine Beta',
            'is_active' => true,
        ]);

        // 2. Perform correction for a date with no existing processed record (should create)
        $response = $this->postJson('/api/v1/admin/attendance/correction', [
            'employee_id' => $this->employee1->id,
            'date' => '2026-06-20',
            'attendance_status' => 'present',
            'check_in' => '09:00',
            'check_out' => '18:00',
            'site_id' => $anotherSite->id,
            'remarks' => 'Manual mark in/out'
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.attendance_status', 'present')
            ->assertJsonPath('data.remarks', 'Manual mark in/out')
            ->assertJsonPath('data.working_hours', 9);

        // Verify record was created in DB
        $record = AttendanceProcessed::where('employee_id', $this->employee1->id)
            ->where('date', '2026-06-20')
            ->first();
        $this->assertNotNull($record);
        $this->assertEquals(9.0, $record->working_hours);
        $this->assertEquals('present', $record->attendance_status);

        // Verify employee's site was updated
        $this->employee1->refresh();
        $this->assertEquals($anotherSite->id, $this->employee1->site_id);

        // 3. Update the same record (should update)
        $response = $this->postJson('/api/v1/admin/attendance/correction', [
            'employee_id' => $this->employee1->id,
            'date' => '2026-06-20',
            'attendance_status' => 'exception',
            'check_in' => '09:00',
            'check_out' => '13:00',
            'remarks' => 'Half day due to issue'
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.attendance_status', 'absent')
            ->assertJsonPath('data.remarks', '[Exception] Half day due to issue')
            ->assertJsonPath('data.working_hours', 4);

        // Verify in DB
        $record->refresh();
        $this->assertEquals('absent', $record->attendance_status);
        $this->assertEquals(4.0, $record->working_hours);
        $this->assertEquals('[Exception] Half day due to issue', $record->remarks);

        // 4. Validate check-out before check-in validation error
        $response = $this->postJson('/api/v1/admin/attendance/correction', [
            'employee_id' => $this->employee1->id,
            'date' => '2026-06-21',
            'attendance_status' => 'present',
            'check_in' => '17:00',
            'check_out' => '13:00'
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Check-out must be greater than check-in');
    }

    public function test_daily_attendance_using_from_date_without_month_year()
    {
        // Setup processed attendance record for 2026-06-15
        AttendanceProcessed::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-06-15',
            'check_in' => '2026-06-15 09:00:00',
            'check_out' => '2026-06-15 18:00:00',
            'working_hours' => 9.0,
            'attendance_status' => 'present'
        ]);

        // Hit the API with view_type=daily and from_date=2026-06-15, with and without month & year parameters.
        // It should return the attendance record of Amit Sharma on 15 Jun 2026.
        $response = $this->getJson('/api/v1/admin/attendance?from_date=2026-06-15&view_type=daily&month=6&year=2026');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));

        $emp1Data = collect($response->json('data'))->firstWhere('employee_id', $this->employee1->id);
        $emp2Data = collect($response->json('data'))->firstWhere('employee_id', $this->employee2->id);

        $this->assertNotNull($emp1Data);
        $this->assertEquals($this->employee1->id, $emp1Data['employee_id']);
        $this->assertEquals('15 Jun 2026', $emp1Data['date']);
        $this->assertEquals('present', $emp1Data['attendance_status']);

        $this->assertNotNull($emp2Data);
        $this->assertEquals($this->employee2->id, $emp2Data['employee_id']);
        $this->assertEquals('15 Jun 2026', $emp2Data['date']);
        $this->assertEquals('absent', $emp2Data['attendance_status']);

        // Also test without month and year parameters
        $response2 = $this->getJson('/api/v1/admin/attendance?from_date=2026-06-15&view_type=daily');
        $response2->assertStatus(200);
        $this->assertCount(2, $response2->json('data'));

        $emp1Data2 = collect($response2->json('data'))->firstWhere('employee_id', $this->employee1->id);
        $emp2Data2 = collect($response2->json('data'))->firstWhere('employee_id', $this->employee2->id);

        $this->assertNotNull($emp1Data2);
        $this->assertEquals($this->employee1->id, $emp1Data2['employee_id']);
        $this->assertEquals('15 Jun 2026', $emp1Data2['date']);
        $this->assertEquals('present', $emp1Data2['attendance_status']);

        $this->assertNotNull($emp2Data2);
        $this->assertEquals($this->employee2->id, $emp2Data2['employee_id']);
        $this->assertEquals('15 Jun 2026', $emp2Data2['date']);
        $this->assertEquals('absent', $emp2Data2['attendance_status']);
    }

    public function test_monthly_attendance_list_shows_paid_and_unpaid_leaves()
    {
        // 1. Create a paid leave type and an approved leave for employee 1
        $paidLeaveType = \App\Models\LeaveType::create([
            'name' => 'Paid Sick Leave',
            'leave_category' => 'paid',
            'allowed_days' => 10,
            'is_active' => true,
        ]);
        \App\Models\Leave::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => $paidLeaveType->id,
            'from_date' => '2026-06-10',
            'to_date' => '2026-06-12', // 3 days
            'reason' => 'Fever',
            'status' => 'approved',
        ]);

        // 2. Create an unpaid leave type and an approved leave for employee 1
        $unpaidLeaveType = \App\Models\LeaveType::create([
            'name' => 'Unpaid Casual Leave',
            'leave_category' => 'unpaid',
            'allowed_days' => 5,
            'is_active' => true,
        ]);
        \App\Models\Leave::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => $unpaidLeaveType->id,
            'from_date' => '2026-06-20',
            'to_date' => '2026-06-21', // 2 days
            'reason' => 'Personal work',
            'status' => 'approved',
        ]);

        // Hit the monthly attendance API
        $response = $this->getJson('/api/v1/admin/attendance?month=6&year=2026&view_type=monthly');
        $response->assertStatus(200);

        // Verify paid_leave and unpaid_leave columns for Employee 1
        $employeeData = collect($response->json('data'))->firstWhere('employee_id', $this->employee1->id);
        $this->assertNotNull($employeeData);
        $this->assertEquals(3, $employeeData['paid_leave']);
        $this->assertEquals(2, $employeeData['unpaid_leave']);
        $this->assertEquals(5, $employeeData['leave']); // total leaves
    }

    public function test_attendance_list_respects_employee_joining_date()
    {
        // Create an employee who joins on 2026-06-10
        $employeeJoinedLate = Employee::create([
            'employee_code' => 'EMP9009',
            'name' => 'Late Joiner',
            'joining_date' => '2026-06-10',
            'is_active' => 1,
            'site_id' => $this->site->id,
            'department_id' => $this->departmentId,
            'designation_id' => $this->employee1->designation_id,
        ]);

        // Monthly view for May 2026: Late Joiner should NOT appear
        $responseMay = $this->getJson('/api/v1/admin/attendance?month=5&year=2026&view_type=monthly');
        $responseMay->assertStatus(200);
        $this->assertNull(collect($responseMay->json('data'))->firstWhere('employee_id', $employeeJoinedLate->id));

        // Monthly view for June 2026: Late Joiner SHOULD appear
        $responseJune = $this->getJson('/api/v1/admin/attendance?month=6&year=2026&view_type=monthly');
        $responseJune->assertStatus(200);
        $this->assertNotNull(collect($responseJune->json('data'))->firstWhere('employee_id', $employeeJoinedLate->id));

        // Daily view for 2026-06-05 (before joining): Late Joiner should NOT appear
        $responseDailyBefore = $this->getJson('/api/v1/admin/attendance?from_date=2026-06-05&view_type=daily');
        $responseDailyBefore->assertStatus(200);
        $this->assertNull(collect($responseDailyBefore->json('data'))->firstWhere('employee_id', $employeeJoinedLate->id));

        // Daily view for 2026-06-15 (after joining): Late Joiner SHOULD appear
        $responseDailyAfter = $this->getJson('/api/v1/admin/attendance?from_date=2026-06-15&view_type=daily');
        $responseDailyAfter->assertStatus(200);
        $this->assertNotNull(collect($responseDailyAfter->json('data'))->firstWhere('employee_id', $employeeJoinedLate->id));
    }
}
