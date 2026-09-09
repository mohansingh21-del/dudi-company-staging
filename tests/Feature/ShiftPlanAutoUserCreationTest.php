<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\Role;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftPlanAutoUserCreationTest extends TestCase
{
    use RefreshDatabase;

    private $adminUser;
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

    public function test_creating_shift_plan_auto_creates_user_if_employee_has_no_role_user()
    {
        $supervisorRole = Role::create([
            'name' => 'Supervisor',
            'slug' => 'supervisor',
            'is_active' => 1
        ]);

        $inchargeRole = Role::create([
            'name' => 'Site Incharge',
            'slug' => 'site-incharge',
            'is_active' => 1
        ]);

        // Create supervisor and site incharge employees WITHOUT role_user_id
        $supervisorEmployee = Employee::create([
            'employee_code' => 'EMP-SUP-999',
            'name' => 'John Supervisor No User',
            'joining_date' => '2026-01-01',
            'designation_id' => $supervisorRole->id,
            'is_active' => 1,
            'role_user_id' => null
        ]);

        $siteInchargeEmployee = Employee::create([
            'employee_code' => 'EMP-INC-999',
            'name' => 'Alice Incharge No User',
            'joining_date' => '2026-01-01',
            'designation_id' => $inchargeRole->id,
            'is_active' => 1,
            'role_user_id' => null
        ]);

        $planningDate = \Carbon\Carbon::now()->addDay()->format('Y-m-d');

        $payload = [
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 20,
            'supervisor_id' => $supervisorEmployee->id,
            'site_incharge_id' => $siteInchargeEmployee->id,
        ];

        $response = $this->postJson('/api/v1/admin/shift-plans', $payload);

        $response->assertStatus(201);

        // Verify that the employees now have role_user_id filled
        $supervisorEmployee->refresh();
        $siteInchargeEmployee->refresh();

        $this->assertNotNull($supervisorEmployee->role_user_id);
        $this->assertNotNull($siteInchargeEmployee->role_user_id);

        // Verify that user accounts were created
        $supRoleUser = \App\Models\RoleUser::find($supervisorEmployee->role_user_id);
        $incRoleUser = \App\Models\RoleUser::find($siteInchargeEmployee->role_user_id);

        $this->assertNotNull($supRoleUser);
        $this->assertNotNull($incRoleUser);

        $supUser = User::find($supRoleUser->user_id);
        $incUser = User::find($incRoleUser->user_id);

        $this->assertNotNull($supUser);
        $this->assertNotNull($incUser);

        $this->assertEquals('emp-sup-999@dudicoalmine.com', $supUser->email);
        $this->assertEquals('emp-inc-999@dudicoalmine.com', $incUser->email);

        // Check database to ensure shift plan was inserted with the user IDs
        $this->assertDatabaseHas('shift_plans', [
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'supervisor_id' => $supUser->id,
            'site_incharge_id' => $incUser->id,
        ]);
    }
}
