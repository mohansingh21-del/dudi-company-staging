<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Employee;
use App\Models\ShiftPlan;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftPlanOverviewTest extends TestCase
{
    use RefreshDatabase;

    private $adminUser;
    private $supervisorEmployee;
    private $siteInchargeEmployee;
    private $shift;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($adminRole);

        // Create Supervisor Role, User, and Employee
        $supervisorRole = Role::create([
            'name' => 'Supervisor',
            'slug' => 'supervisor',
            'is_active' => 1
        ]);
        $supervisorUser = User::create([
            'email' => 'supervisor@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $supRoleUser = RoleUser::create([
            'user_id' => $supervisorUser->id,
            'role_id' => $supervisorRole->id
        ]);
        $this->supervisorEmployee = Employee::create([
            'employee_code' => 'EMP-SUP-001',
            'name' => 'John Supervisor',
            'father_name' => 'Father',
            'dob' => '1990-01-01',
            'gender' => 'male',
            'mobile' => '9999999999',
            'address' => 'Colony 1',
            'joining_date' => '2026-01-01',
            'employee_type' => 'permanent',
            'designation_id' => $supervisorRole->id,
            'salary_type' => 'monthly',
            'basic_salary' => 30000,
            'is_active' => 1,
            'role_user_id' => $supRoleUser->id
        ]);

        // Create Site Incharge Role, User, and Employee
        $inchargeRole = Role::create([
            'name' => 'Site Incharge',
            'slug' => 'site-incharge',
            'is_active' => 1
        ]);
        $inchargeUser = User::create([
            'email' => 'incharge@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $incRoleUser = RoleUser::create([
            'user_id' => $inchargeUser->id,
            'role_id' => $inchargeRole->id
        ]);
        $this->siteInchargeEmployee = Employee::create([
            'employee_code' => 'EMP-INC-001',
            'name' => 'Alice Incharge',
            'father_name' => 'Father',
            'dob' => '1990-01-01',
            'gender' => 'female',
            'mobile' => '9999999998',
            'address' => 'Colony 2',
            'joining_date' => '2026-01-01',
            'employee_type' => 'permanent',
            'designation_id' => $inchargeRole->id,
            'salary_type' => 'monthly',
            'basic_salary' => 50000,
            'is_active' => 1,
            'role_user_id' => $incRoleUser->id
        ]);

        // Create Shift & Site
        $this->shift = Shift::create([
            'shift_name' => 'A',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        $this->site = Site::create([
            'site_name' => 'Block-04 West',
            'address' => 'Site Address',
            'is_active' => 1
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($this->adminUser);
    }

    public function test_can_fetch_shift_plans_overview_with_stats()
    {
        $planningDate = \Carbon\Carbon::now()->format('Y-m-d');

        // Create shift plan
        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'actual_bcm' => 42200,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // Assign employee to shift
        EmployeeShiftAssignment::create([
            'employee_id' => $this->supervisorEmployee->id,
            'shift_id' => $this->shift->id,
            'from_date' => $planningDate,
            'to_date' => null
        ]);

        $response = $this->getJson('/api/v1/admin/shift-plans/overview?period=monthly');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'stats' => [
                    'total_scheduled_shifts',
                    'active_personnel',
                    'target_bcm',
                    'actual_bcm',
                    'current_efficiency'
                ],
                'shift_plans' => [
                    '*' => [
                        'id',
                        'reference_no',
                        'planning_date',
                        'shift_name',
                        'site_name',
                        'target_bcm',
                        'actual_bcm',
                        'status'
                    ]
                ]
            ],
            'pagination' => [
                'current_page',
                'last_page',
                'per_page',
                'total'
            ]
        ]);

        $data = $response->json('data');
        $this->assertEquals(1, $data['stats']['total_scheduled_shifts']);
        $this->assertEquals(1, $data['stats']['active_personnel']);
        $this->assertEquals(45000, $data['stats']['target_bcm']);
        $this->assertEquals(42200, $data['stats']['actual_bcm']);
        $this->assertEquals(94, $data['stats']['current_efficiency']);
    }

    public function test_can_filter_overview_by_site_and_supervisor()
    {
        $planningDate = \Carbon\Carbon::now()->format('Y-m-d');

        // Create shift plan
        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'actual_bcm' => 42200,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // Filter by matching site
        $response = $this->getJson("/api/v1/admin/shift-plans/overview?site_id={$this->site->id}");
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.shift_plans'));

        // Filter by non-matching site
        $response = $this->getJson("/api/v1/admin/shift-plans/overview?site_id=999");
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.shift_plans'));

        // Filter by matching supervisor (employee ID)
        $response = $this->getJson("/api/v1/admin/shift-plans/overview?supervisor_id={$this->supervisorEmployee->id}");
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.shift_plans'));
    }
}
