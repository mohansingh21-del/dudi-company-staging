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

class ShiftPlanDetailTest extends TestCase
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

    public function test_can_fetch_shift_plan_details_with_machinery_allocation()
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
            'equipment_name' => 'EX-01',
            'is_active' => 1,
        ]);
        $dumperMachine = EquipmentName::create([
            'equipment_id' => $dumperCategory->id,
            'equipment_name' => 'DM-01',
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

        // GET /api/v1/admin/shift-plans/{id}
        $response = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'id',
                'reference_no',
                'planning_date',
                'shift_name',
                'site_name',
                'target_bcm',
                'actual_bcm',
                'machinery_allocations' => [
                    '*' => [
                        'allocation_id',
                        'machine_id',
                        'machine_number',
                        'category_id',
                        'category_name',
                        'dumpers' => [
                            '*' => [
                                'allocation_id',
                                'machine_id',
                                'machine_number',
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $data = $response->json('data');
        $this->assertCount(1, $data['machinery_allocations']);
        
        $topMachinery = $data['machinery_allocations'][0];
        $this->assertEquals($parentAllocation->id, $topMachinery['allocation_id']);
        $this->assertEquals($excavatorMachine->id, $topMachinery['machine_id']);
        $this->assertEquals('EX-01', $topMachinery['machine_number']);
        $this->assertEquals('Excavator', $topMachinery['category_name']);
        
        $this->assertCount(1, $topMachinery['dumpers']);
        $childMachinery = $topMachinery['dumpers'][0];
        $this->assertEquals($childAllocation->id, $childMachinery['allocation_id']);
        $this->assertEquals($dumperMachine->id, $childMachinery['machine_id']);
        $this->assertEquals('DM-01', $childMachinery['machine_number']);
    }
}
