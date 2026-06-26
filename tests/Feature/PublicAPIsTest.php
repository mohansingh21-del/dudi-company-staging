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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAPIsTest extends TestCase
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

    public function test_get_machines_by_shift()
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

        // Create Equipment categories
        $excavatorCategory = Equipment::create(['name' => 'Excavator', 'is_active' => 1]);
        $dumperCategory = Equipment::create(['name' => 'Dumper', 'is_active' => 1]);

        // Create Machine instances
        $excavatorMachine = EquipmentName::create([
            'equipment_id' => $excavatorCategory->id,
            'equipment_name' => 'EX-001',
            'is_active' => 1,
        ]);
        $dumperMachine = EquipmentName::create([
            'equipment_id' => $dumperCategory->id,
            'equipment_name' => 'DM-003',
            'is_active' => 1,
        ]);

        // Allocate Excavator as top-level machinery
        $parentAllocation = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $excavatorMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        // Allocate Dumper nested under the Excavator machine (parent_equipment_id = excavatorMachine->id)
        $childAllocation = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $dumperMachine->id,
            'parent_equipment_id' => $excavatorMachine->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        // GET /api/v1/shift-plans/{shift_id}/machines
        $response = $this->getJson("/api/v1/shift-plans/{$this->shift->id}/machines");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 200,
            'message' => 'Allocated equipment retrieved successfully.',
            'data' => [
                [
                    'allocation_id' => $parentAllocation->id,
                    'machine_id' => $excavatorMachine->id,
                    'machine_number' => 'EX-001',
                    'category_id' => $excavatorCategory->id,
                    'category_name' => 'Excavator',
                ],
                [
                    'allocation_id' => $childAllocation->id,
                    'machine_id' => $dumperMachine->id,
                    'machine_number' => 'DM-003',
                    'category_id' => $dumperCategory->id,
                    'category_name' => 'Dumper',
                ]
            ]
        ]);
    }

    public function test_get_machines_by_shift_not_found()
    {
        // GET /api/v1/shift-plans/{invalid_shift_id}/machines
        $response = $this->getJson("/api/v1/shift-plans/99999/machines");
        $response->assertStatus(404);
        $response->assertJsonFragment([
            'message' => 'Shift Not Found.'
        ]);
    }

    public function test_get_machines_by_shift_custom_date()
    {
        $planningDate = '2026-06-25'; // custom date

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
            'reference_no' => 'SP-TEST-002'
        ]);

        $excavatorCategory = Equipment::create(['name' => 'Excavator2', 'is_active' => 1]);
        $excavatorMachine = EquipmentName::create([
            'equipment_id' => $excavatorCategory->id,
            'equipment_name' => 'EX-002',
            'is_active' => 1,
        ]);

        $parentAllocation = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $excavatorMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        // Query with custom date param
        $response = $this->getJson("/api/v1/shift-plans/{$this->shift->id}/machines?date=2026-06-25");

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'machine_number' => 'EX-002'
        ]);
    }

    public function test_get_machines_by_shift_fallback_date()
    {
        // No shift plans exist for today, but one exists for a past date (fallback)
        $pastDate = '2026-05-15';

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $pastDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'actual_bcm' => 42200,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-003'
        ]);

        $excavatorCategory = Equipment::create(['name' => 'Excavator3', 'is_active' => 1]);
        $excavatorMachine = EquipmentName::create([
            'equipment_id' => $excavatorCategory->id,
            'equipment_name' => 'EX-003',
            'is_active' => 1,
        ]);

        $parentAllocation = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $excavatorMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        // Query without date param, should fallback to latest date ('2026-05-15')
        $response = $this->getJson("/api/v1/shift-plans/{$this->shift->id}/machines");

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'machine_number' => 'EX-003'
        ]);
    }


    public function test_get_reported_by_employee_name()
    {
        // Create matching employee
        $workerRole = Role::create([
            'name' => 'Worker',
            'slug' => 'worker',
            'is_active' => 1
        ]);
        $employee = Employee::create([
            'employee_code' => 101,
            'name' => 'deepak',
            'joining_date' => '2026-01-01',
            'designation_id' => $workerRole->id,
            'is_active' => 1,
        ]);

        // Create non-matching employee
        Employee::create([
            'employee_code' => 102,
            'name' => 'shashank',
            'joining_date' => '2026-01-01',
            'designation_id' => $workerRole->id,
            'is_active' => 1,
        ]);

        // GET /api/v1/search-employee/search=deepak
        $response = $this->getJson('/api/v1/search-employee/search=deepak');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 200,
            'message' => 'worker retrieved successfully.',
            'data' => [
                [
                    'id' => $employee->id,
                    'employee_code' => 101,
                    'name' => 'deepak',
                    'is_active' => 1
                ]
            ]
        ]);

        // Assert we don't return non-matching
        $this->assertCount(1, $response->json('data'));
    }
}
