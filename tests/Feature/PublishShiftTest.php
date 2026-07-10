<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Employee;
use App\Models\ShiftPlan;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\ShiftEquipmentAllocation;
use App\Models\ShiftWorkforceDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishShiftTest extends TestCase
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
            'joining_date' => '2026-01-01',
            'designation_id' => $supervisorRole->id,
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
            'joining_date' => '2026-01-01',
            'designation_id' => $inchargeRole->id,
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

    public function test_validate_publish_via_publish_endpoint_returns_checks()
    {
        $planningDate = \Carbon\Carbon::now()->format('Y-m-d');

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/publish");

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'shift_plan_id',
                'can_publish',
                'current_status',
                'validations' => [
                    'precondition_draft',
                    'equipment_allocated',
                    'workforce_deployed',
                    'supervisor_assigned',
                    'site_incharge_assigned',
                    'target_bcm_defined'
                ]
            ]
        ]);

        $this->assertFalse($response->json('data.can_publish'));
        $this->assertFalse($response->json('data.validations.equipment_allocated.status'));
        $this->assertFalse($response->json('data.validations.workforce_deployed.status'));
        $this->assertTrue($response->json('data.validations.supervisor_assigned.status'));
    }

    public function test_publish_fails_if_not_in_draft_status()
    {
        $planningDate = \Carbon\Carbon::now()->format('Y-m-d');

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'planned',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/publish");

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 422,
            'message' => 'Shift plan cannot be published due to validation errors.',
            'data' => [
                'validations' => [
                    'precondition_draft' => [
                        'status' => false,
                    ]
                ]
            ]
        ]);
    }

    public function test_publish_fails_if_no_excavator_allocated()
    {
        $planningDate = \Carbon\Carbon::now()->format('Y-m-d');

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // Deploy workforce but do not allocate Excavator
        ShiftWorkforceDeployment::create([
            'shift_plan_id' => $shiftPlan->id,
            'employee_id' => $this->supervisorEmployee->id,
            'relay_shift' => 'relay_1',
            'designation' => 'Supervisor',
            'is_borrowed' => false,
            'deployed_by' => $this->adminUser->id,
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/publish");

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 422,
            'message' => 'Shift plan cannot be published due to validation errors.',
            'data' => [
                'validations' => [
                    'equipment_allocated' => [
                        'status' => false,
                    ]
                ]
            ]
        ]);
    }

    public function test_publish_fails_if_workforce_missing()
    {
        $planningDate = \Carbon\Carbon::now()->format('Y-m-d');

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // Allocate Excavator, but no workforce
        $excavatorCategory = Equipment::create(['name' => 'Excavator', 'is_active' => 1]);
        $excavatorMachine = EquipmentName::create([
            'equipment_id' => $excavatorCategory->id,
            'equipment_name' => 'EX-01',
            'is_active' => 1,
        ]);
        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $excavatorMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/publish");

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 422,
            'message' => 'Shift plan cannot be published due to validation errors.',
            'data' => [
                'validations' => [
                    'workforce_deployed' => [
                        'status' => false,
                    ]
                ]
            ]
        ]);
    }

    public function test_publish_succeeds_when_all_conditions_met()
    {
        $planningDate = \Carbon\Carbon::now()->format('Y-m-d');

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // Allocate Excavator
        $excavatorCategory = Equipment::create(['name' => 'Excavator', 'is_active' => 1]);
        $excavatorMachine = EquipmentName::create([
            'equipment_id' => $excavatorCategory->id,
            'equipment_name' => 'EX-01',
            'is_active' => 1,
        ]);
        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $excavatorMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        // Deploy workforce
        ShiftWorkforceDeployment::create([
            'shift_plan_id' => $shiftPlan->id,
            'employee_id' => $this->supervisorEmployee->id,
            'relay_shift' => 'relay_1',
            'designation' => 'Supervisor',
            'is_borrowed' => false,
            'deployed_by' => $this->adminUser->id,
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/publish");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 200,
            'message' => 'Shift Published Successfully.',
        ]);

        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'id',
                'status',
                'published_by',
                'publisher_name',
                'published_at',
            ]
        ]);

        $this->assertEquals('in_progress', $response->json('data.status'));
        $this->assertEquals($this->adminUser->id, $response->json('data.published_by'));
        $this->assertNotNull($response->json('data.published_at'));
    }

    public function test_publish_fails_if_planning_date_is_in_future()
    {
        $futureDate = \Carbon\Carbon::tomorrow()->format('Y-m-d');

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $futureDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-FUTURE'
        ]);

        // Allocate Excavator
        $excavatorCategory = Equipment::create(['name' => 'Excavator', 'is_active' => 1]);
        $excavatorMachine = EquipmentName::create([
            'equipment_id' => $excavatorCategory->id,
            'equipment_name' => 'EX-01',
            'is_active' => 1,
        ]);
        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $excavatorMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        // Deploy workforce
        ShiftWorkforceDeployment::create([
            'shift_plan_id' => $shiftPlan->id,
            'employee_id' => $this->supervisorEmployee->id,
            'relay_shift' => 'relay_1',
            'designation' => 'Supervisor',
            'is_borrowed' => false,
            'deployed_by' => $this->adminUser->id,
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/publish");

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 422,
            'message' => 'Shift plan cannot be published due to validation errors.',
            'data' => [
                'validations' => [
                    'planning_date_reached' => [
                        'status' => false,
                        'message' => 'Cannot publish shift plan before its planned date.'
                    ]
                ]
            ]
        ]);
    }
}
