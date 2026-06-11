<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftHistory;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkingDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftChangeMonthlyRosterTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $site;
    protected $employee;
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

        // Create Site
        $this->site = Site::create([
            'site_name' => 'Saran Coal Mine',
            'is_active' => 1
        ]);

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

        // Create employee
        $this->employee = Employee::create([
            'employee_code' => 'EMP100',
            'name' => 'Neha Jain',
            'joining_date' => '2026-01-01',
            'site_id' => $this->site->id,
            'is_active' => 1
        ]);

        // Configure WorkingDays (Sunday non-working, Saturday non-working)
        WorkingDay::create(['day' => 'Monday', 'is_working' => 1]);
        WorkingDay::create(['day' => 'Tuesday', 'is_working' => 1]);
        WorkingDay::create(['day' => 'Wednesday', 'is_working' => 1]);
        WorkingDay::create(['day' => 'Thursday', 'is_working' => 1]);
        WorkingDay::create(['day' => 'Friday', 'is_working' => 1]);
        WorkingDay::create(['day' => 'Saturday', 'is_working' => 0]);
        WorkingDay::create(['day' => 'Sunday', 'is_working' => 0]);
    }

    public function test_can_retrieve_monthly_roster()
    {
        // 1. Setup shift assignment for employee
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-05-01',
        ]);

        // 2. Create a public holiday on 2026-05-01 (May 1st, Friday)
        Holiday::create([
            'holiday_name' => 'Labor Day',
            'holiday_date' => '2026-05-01',
            'site_id' => $this->site->id,
            'holiday_type' => 'Public Holiday',
            'is_active' => 1
        ]);

        // 3. Create approved leave on 2026-05-04 (May 4th, Monday)
        EmployeeLeave::create([
            'employee_id' => $this->employee->id,
            'from_date' => '2026-05-04',
            'to_date' => '2026-05-04',
            'status' => 'approved',
            'reason' => 'Sick Leave'
        ]);

        // Make GET request
        $response = $this->json('GET', '/api/v1/admin/shift-rotation/monthly-roster', [
            'employee_id' => $this->employee->id,
            'month' => '2026-05'
        ]);

        $response->assertStatus(200);

        // Verify basic structure
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'employee' => [
                    'id',
                    'name',
                    'employee_code',
                    'department',
                    'designation',
                    'site'
                ],
                'month_year',
                'monthly_summary',
                'timeline_weeks' => [
                    '*' => [
                        'week_name',
                        'days_range',
                        'shift_name',
                        'label',
                        'pill_color'
                    ]
                ]
            ]
        ]);

        // Verify specific values
        $response->assertJsonFragment([
            'name' => 'Neha Jain',
            'month_year' => 'May 2026'
        ]);

        // Get decoded JSON data
        $data = $response->json('data');

        // Check Week 1 - should have resolved to Morning Shift
        $week1 = $data['timeline_weeks'][0];
        $this->assertEquals('Week 1', $week1['week_name']);
        $this->assertEquals('Morning Shift', $week1['shift_name']);
        $this->assertEquals('08:00 AM - 04:00 PM', $week1['label']);

        // Check Week 2 - should be Morning Shift
        $week2 = $data['timeline_weeks'][1];
        $this->assertEquals('Week 2', $week2['week_name']);
        $this->assertEquals('Morning Shift', $week2['shift_name']);
        $this->assertEquals('08:00 AM - 04:00 PM', $week2['label']);
    }

    public function test_resolves_historical_shifts()
    {
        // 1. Setup initial assignment on Shift A starting May 1st
        $assignment = EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-05-01',
        ]);

        // 2. Update to Shift B starting May 15th (fires Eloquent boot listeners automatically)
        $assignment->update([
            'shift_id' => $this->shiftB->id,
            'from_date' => '2026-05-15',
        ]);

        // Get monthly roster
        $response = $this->json('GET', '/api/v1/admin/shift-rotation/monthly-roster', [
            'employee_id' => $this->employee->id,
            'month' => '2026-05'
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Before May 15, Week 2 should resolve to Morning Shift (Shift A)
        $week2 = $data['timeline_weeks'][1];
        $this->assertEquals('Week 2', $week2['week_name']);
        $this->assertEquals('Morning Shift', $week2['shift_name']);

        // From May 15, Week 3 should resolve to Night Shift (Shift B)
        $week3 = $data['timeline_weeks'][2];
        $this->assertEquals('Week 3', $week3['week_name']);
        $this->assertEquals('Night Shift', $week3['shift_name']);
    }

    public function test_can_retrieve_monthly_roster_using_show_endpoint()
    {
        // Setup shift assignment
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => '2026-05-01',
        ]);

        // Request monthly roster via show API route
        $response = $this->json('GET', "/api/v1/admin/shift-rotation/{$this->employee->id}", [
            'month' => '2026-05'
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'name' => 'Neha Jain',
            'month_year' => 'May 2026'
        ]);
    }

    public function test_dates_before_initial_assignment_are_off()
    {
        // Setup current assignment starting mid-month (e.g. May 15th) on Shift B
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => '2026-05-15',
        ]);

        // Get monthly roster
        $response = $this->json('GET', "/api/v1/admin/shift-rotation/{$this->employee->id}", [
            'month' => '2026-05'
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        // Week 2 (before May 15th) should resolve to null (blank)
        $week2 = $data['timeline_weeks'][1];
        $this->assertEquals('Week 2', $week2['week_name']);
        $this->assertNull($week2['shift_name']);

        // Week 3 (starts May 15th) should resolve to Night Shift
        $week3 = $data['timeline_weeks'][2];
        $this->assertEquals('Week 3', $week3['week_name']);
        $this->assertEquals('Night Shift', $week3['shift_name']);
    }
}
