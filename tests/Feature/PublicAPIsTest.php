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
use App\Models\BreakdownTicket;
use App\Models\BreakdownType;
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

    public function test_find_shift_by_datetime_regular_shift()
    {
        // A regular shift exists: Morning Shift (08:00:00 - 16:00:00)
        // Let's create a shift plan for it on today's date
        $planningDate = '2026-06-27';
        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id, // shift A starts at 08:00:00, ends at 16:00:00
            'site_id' => $this->site->id,
            'target_bcm' => 10000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-REGULAR-123'
        ]);

        // Create category, machine and allocate
        $excavatorCategory = Equipment::create(['name' => 'Excavator', 'is_active' => 1]);
        $excavatorMachine = EquipmentName::create([
            'equipment_id' => $excavatorCategory->id,
            'equipment_name' => 'EX-001',
            'is_active' => 1,
        ]);

        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $excavatorMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        // Query the public API without authentication (completely public)
        $response = $this->getJson("/api/v1/shifts/by-datetime?date=2026-06-27&time=10:15:00");

        $response->assertStatus(200);
        $response->assertExactJson([
            'status' => 200,
            'message' => 'Shift details retrieved successfully.',
            'data' => [
                'id' => $this->shift->id,
                'name' => 'A',
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
                'shift_plan_id' => $shiftPlan->id,
                'site' => [
                    'id' => $this->site->id,
                    'site_name' => $this->site->site_name,
                    'address' => $this->site->address,
                ],
                'drivers' => [],
                'workforce' => [],
                'machines' => [
                    [
                        'machine_id' => $excavatorMachine->id,
                        'machine_name' => 'EX-001',
                        'category_id' => $excavatorCategory->id,
                        'category_name' => 'Excavator',
                        'parent_machine_id' => null,
                        'breakdown' => null,
                    ]
                ]
            ]
        ]);
    }

    public function test_find_shift_by_datetime_with_nested_machine_parent_machine_id()
    {
        $planningDate = '2026-06-27';
        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 10000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-NESTED-123'
        ]);

        $excavatorCategory = Equipment::create(['name' => 'Excavator', 'is_active' => 1]);
        $dumperCategory = Equipment::create(['name' => 'Dumper', 'is_active' => 1]);

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

        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $excavatorMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $dumperMachine->id,
            'parent_equipment_id' => $excavatorMachine->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        $response = $this->getJson("/api/v1/shifts/by-datetime?date=2026-06-27&time=10:15:00");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 200,
            'data' => [
                'id' => $this->shift->id,
                'machines' => [
                    [
                        'machine_id' => $excavatorMachine->id,
                        'parent_machine_id' => null,
                    ],
                    [
                        'machine_id' => $dumperMachine->id,
                        'parent_machine_id' => $excavatorMachine->id,
                    ]
                ]
            ]
        ]);
    }

    public function test_find_shift_by_datetime_night_shift_post_midnight()
    {
        // Create a Night Shift: 22:00:00 - 06:00:00
        $nightShift = Shift::create([
            'shift_name' => 'Night Shift',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 1,
            'is_active' => 1
        ]);

        // Create shift plan for June 27th Night Shift (meaning it spans into morning of June 28th)
        $shiftPlan = ShiftPlan::create([
            'planning_date' => '2026-06-27',
            'shift_id' => $nightShift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 12000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-NIGHT-123'
        ]);

        // Query the public API for 2 AM on June 28th.
        // It should match the Night Shift, and planning date should resolve to June 27th, and fetch the shift plan.
        $response = $this->getJson("/api/v1/shifts/by-datetime?date=2026-06-28&time=02:00:00");

        $response->assertStatus(200);
        $response->assertExactJson([
            'status' => 200,
            'message' => 'Shift details retrieved successfully.',
            'data' => [
                'id' => $nightShift->id,
                'name' => 'Night Shift',
                'start_time' => '22:00:00',
                'end_time' => '06:00:00',
                'shift_plan_id' => $shiftPlan->id,
                'site' => [
                    'id' => $this->site->id,
                    'site_name' => $this->site->site_name,
                    'address' => $this->site->address,
                ],
                'drivers' => [],
                'workforce' => [],
                'machines' => []
            ]
        ]);
    }

    public function test_find_shift_by_datetime_no_shift_covers_time()
    {
        $response = $this->getJson("/api/v1/shifts/by-datetime?date=2026-06-27&time=invalid-time");

        $response->assertStatus(422);
    }

    public function test_find_shift_by_datetime_space_separated()
    {
        // Morning Shift (08:00:00 - 16:00:00)
        $planningDate = '2026-06-27';
        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id, 
            'site_id' => $this->site->id,
            'target_bcm' => 10000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-SPACE-123'
        ]);

        // Query with space-separated datetime
        $response = $this->getJson("/api/v1/shifts/by-datetime?datetime=2026-06-27 12:00:00");

        $response->assertStatus(200);
        $response->assertExactJson([
            'status' => 200,
            'message' => 'Shift details retrieved successfully.',
            'data' => [
                'id' => $this->shift->id,
                'name' => 'A',
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
                'shift_plan_id' => $shiftPlan->id,
                'site' => [
                    'id' => $this->site->id,
                    'site_name' => $this->site->site_name,
                    'address' => $this->site->address,
                ],
                'drivers' => [],
                'workforce' => [],
                'machines' => []
            ]
        ]);
    }

    public function test_find_shift_by_datetime_no_shift_plan_exists()
    {
        // Query the public API for a date/time where an active shift exists, but no ShiftPlan has been created
        $response = $this->getJson("/api/v1/shifts/by-datetime?date=2026-06-27&time=10:15:00");

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 422,
            'message' => 'No shift plan found for the selected date.',
            'data' => null
        ]);
    }

    public function test_find_shift_by_datetime_only_date_returns_all_planned_shifts()
    {
        $planningDate = '2026-06-27';

        // 1. Create another Shift (Shift B)
        $shiftB = Shift::create([
            'shift_name' => 'B',
            'start_time' => '16:00:00',
            'end_time' => '00:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // 2. Create ShiftPlan for Shift A (this->shift)
        $shiftPlanA = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 10000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-A-123'
        ]);

        // 3. Create ShiftPlan for Shift B
        $shiftPlanB = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 12000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-B-123'
        ]);

        // Create category and machine for allocation to Shift A
        $excavatorCategory = Equipment::create(['name' => 'Excavator', 'is_active' => 1]);
        $excavatorMachine = EquipmentName::create([
            'equipment_id' => $excavatorCategory->id,
            'equipment_name' => 'EX-001',
            'is_active' => 1,
        ]);

        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlanA->id,
            'equipment_name_id' => $excavatorMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        // Query the API using only date
        $response = $this->getJson("/api/v1/shifts/by-datetime?date=2026-06-27");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 200,
            'message' => 'Shift details retrieved successfully.',
            'data' => [
                [
                    'id' => $this->shift->id,
                    'name' => 'A',
                    'start_time' => '08:00:00',
                    'end_time' => '16:00:00',
                    'shift_plan_id' => $shiftPlanA->id,
                    'machines' => [
                        [
                            'machine_id' => $excavatorMachine->id,
                            'machine_name' => 'EX-001',
                            'category_id' => $excavatorCategory->id,
                            'category_name' => 'Excavator',
                        ]
                    ]
                ],
                [
                    'id' => $shiftB->id,
                    'name' => 'B',
                    'start_time' => '16:00:00',
                    'end_time' => '00:00:00',
                    'shift_plan_id' => $shiftPlanB->id,
                    'machines' => []
                ]
            ]
        ]);

        // Also query using datetime=2026-06-27 without time
        $response2 = $this->getJson("/api/v1/shifts/by-datetime?datetime=2026-06-27");
        $response2->assertStatus(200);
        $this->assertCount(2, $response2->json('data'));
    }

    public function test_find_shift_by_datetime_excludes_draft_and_closed_shift_plans()
    {
        $planningDate = '2026-06-28';

        // 1. Create a draft ShiftPlan
        $shiftPlanDraft = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 10000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-DRAFT-123'
        ]);

        // 2. Query date-only, should return empty array
        $response1 = $this->getJson("/api/v1/shifts/by-datetime?date={$planningDate}");
        $response1->assertStatus(200);
        $response1->assertJsonPath('data', []);

        // 3. Query datetime-specific, should say no shift plan found (since the only one is draft)
        $response2 = $this->getJson("/api/v1/shifts/by-datetime?date={$planningDate}&time=10:00:00");
        $response2->assertStatus(422);
        $response2->assertJsonFragment([
            'message' => 'No shift plan found for the selected date.'
        ]);
    }

    public function test_find_shift_by_datetime_returns_active_breakdown_details()
    {
        $planningDate = '2026-06-27';
        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 10000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-BREAKDOWN-123'
        ]);

        $excavatorCategory = Equipment::create(['name' => 'ExcavatorX', 'is_active' => 1]);
        $excavatorMachine = EquipmentName::create([
            'equipment_id' => $excavatorCategory->id,
            'equipment_name' => 'EX-X01',
            'is_active' => 1,
        ]);

        $allocation = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $excavatorMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        $breakdownType = BreakdownType::create([
            'breakdown_type' => 'Engine Failure',
            'description' => 'Engine issues',
            'is_active' => 1,
        ]);

        $breakdownTicket = BreakdownTicket::create([
            'ticket_number' => 'BT-TEST-999',
            'shift_id' => $this->shift->id,
            'equipment_id' => $excavatorCategory->id,
            'equipment_name_id' => $excavatorMachine->id,
            'equipment_allocation_id' => $allocation->id,
            'breakdown_date_time' => '2026-06-27 10:00:00',
            'reported_by' => $this->supervisorEmployee->id,
            'breakdown_type_id' => $breakdownType->id,
            'severity' => 'MEDIUM',
            'description' => 'Engine smoking',
            'status' => 'open',
            'downtime_start' => '2026-06-27 10:00:00',
        ]);

        $response = $this->getJson("/api/v1/shifts/by-datetime?date=2026-06-27&time=10:15:00");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 200,
            'data' => [
                'id' => $this->shift->id,
                'machines' => [
                    [
                        'machine_id' => $excavatorMachine->id,
                        'machine_name' => 'EX-X01',
                        'breakdown' => [
                            'id' => $breakdownTicket->id,
                            'ticket_number' => 'BT-TEST-999',
                            'status' => 'open',
                            'severity' => 'MEDIUM',
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function test_find_shift_by_datetime_returns_drivers_and_site_info()
    {
        $planningDate = '2026-06-27';
        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 10000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-DRIVERS-TEST-123'
        ]);

        $driverRole = Role::create([
            'name' => 'Driver',
            'slug' => 'driver',
            'is_active' => 1
        ]);

        $driverUser = User::create([
            'email' => 'testdriver@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);

        $driverRoleUser = RoleUser::create([
            'user_id' => $driverUser->id,
            'role_id' => $driverRole->id
        ]);

        $driverEmployee = Employee::create([
            'employee_code' => 'EMP-DRV-999',
            'name' => 'Driver Bob',
            'joining_date' => '2026-01-01',
            'designation_id' => $driverRole->id,
            'is_active' => 1,
            'role_user_id' => $driverRoleUser->id,
            'mobile' => '9876543210'
        ]);

        \App\Models\ShiftWorkforceDeployment::create([
            'shift_plan_id' => $shiftPlan->id,
            'employee_id' => $driverEmployee->id,
            'relay_shift' => 'general',
            'designation' => 'Driver',
            'status' => 'active',
            'deployed_by' => $this->adminUser->id,
        ]);

        $response = $this->getJson("/api/v1/shifts/by-datetime?date=2026-06-27&time=10:15:00");

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 200,
            'data' => [
                'id' => $this->shift->id,
                'shift_plan_id' => $shiftPlan->id,
                'site' => [
                    'id' => $this->site->id,
                    'site_name' => 'Block-04 West',
                    'address' => 'Site Address',
                ],
                'drivers' => [
                    [
                        'id' => $driverEmployee->id,
                        'employee_code' => 'EMP-DRV-999',
                        'name' => 'Driver Bob',
                        'mobile' => '9876543210',
                        'designation' => 'Driver',
                    ]
                ],
                'workforce' => [
                    [
                        'id' => $driverEmployee->id,
                        'employee_code' => 'EMP-DRV-999',
                        'name' => 'Driver Bob',
                        'mobile' => '9876543210',
                        'designation' => 'Driver',
                    ]
                ]
            ]
        ]);
    }

    public function test_get_public_equipment_names_statuses_and_reasons()
    {
        // 1. Create a category
        $dumperCategory = Equipment::create(['name' => 'DumperCategory', 'is_active' => 1]);

        // 2. Create an active & available machine
        $activeMachine = EquipmentName::create([
            'equipment_id' => $dumperCategory->id,
            'equipment_name' => 'DM-ACTIVE-001',
            'is_active' => 1,
        ]);

        // 3. Create an inactive machine
        $inactiveMachine = EquipmentName::create([
            'equipment_id' => $dumperCategory->id,
            'equipment_name' => 'DM-INACTIVE-002',
            'is_active' => 0,
        ]);

        // 4. Create a machine in maintenance (breakdown ticket open)
        $maintenanceMachine = EquipmentName::create([
            'equipment_id' => $dumperCategory->id,
            'equipment_name' => 'DM-MAINTENANCE-003',
            'is_active' => 1,
        ]);

        $breakdownType = BreakdownType::create([
            'breakdown_type' => 'Hydraulic Issue',
            'description' => 'Hydraulic system failure',
            'is_active' => 1,
        ]);

        BreakdownTicket::create([
            'ticket_number' => 'BT-TEST-888',
            'shift_id' => $this->shift->id,
            'equipment_id' => $dumperCategory->id,
            'equipment_name_id' => $maintenanceMachine->id,
            'breakdown_date_time' => now()->toDateTimeString(),
            'reported_by' => $this->supervisorEmployee->id,
            'breakdown_type_id' => $breakdownType->id,
            'severity' => 'HIGH',
            'status' => 'open',
            'description' => 'Test breakdown description',
        ]);

        // 5. Create a machine allocated to an active shift plan
        $allocatedMachine = EquipmentName::create([
            'equipment_id' => $dumperCategory->id,
            'equipment_name' => 'DM-ALLOCATED-004',
            'is_active' => 1,
        ]);

        $shiftPlan = ShiftPlan::create([
            'planning_date' => now()->format('Y-m-d'),
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 10000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-ALLOC-TEST-99'
        ]);

        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $allocatedMachine->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        // Call GET /api/v1/machine-names/{id}
        $response = $this->getJson("/api/v1/machine-names/{$dumperCategory->id}");

        $response->assertStatus(200);

        // Check the structures in response data
        $data = $response->json('data');
        
        $this->assertCount(3, $data);

        // Assert details of each machine
        $activeRes = collect($data)->firstWhere('id', $activeMachine->id);
        $this->assertEquals('available', $activeRes['status']);
        $this->assertNull($activeRes['short_reason']);

        // Inactive machine should NOT be returned
        $this->assertNull(collect($data)->firstWhere('id', $inactiveMachine->id));

        $maintenanceRes = collect($data)->firstWhere('id', $maintenanceMachine->id);
        $this->assertEquals('unavailable', $maintenanceRes['status']);
        $this->assertEquals('In maintenance', $maintenanceRes['short_reason']);

        $allocatedRes = collect($data)->firstWhere('id', $allocatedMachine->id);
        $this->assertEquals('unavailable', $allocatedRes['status']);
        $this->assertEquals('Already allocated to ' . $this->shift->shift_name, $allocatedRes['short_reason']);
    }

    public function test_get_public_equipment_names_pagination_and_search()
    {
        $dumperCategory = Equipment::create(['name' => 'DumperCategory2', 'is_active' => 1]);

        EquipmentName::create([
            'equipment_id' => $dumperCategory->id,
            'equipment_name' => 'DM-SEARCH-1',
            'is_active' => 1,
        ]);

        EquipmentName::create([
            'equipment_id' => $dumperCategory->id,
            'equipment_name' => 'DM-SEARCH-2',
            'is_active' => 1,
        ]);

        // Search test
        $responseSearch = $this->getJson("/api/v1/machine-names/{$dumperCategory->id}?search=DM-SEARCH-1");
        $responseSearch->assertStatus(200);
        $this->assertCount(1, $responseSearch->json('data'));
        $this->assertEquals('DM-SEARCH-1', $responseSearch->json('data.0.equipment_name'));

        // Pagination test
        $responsePaginated = $this->getJson("/api/v1/machine-names/{$dumperCategory->id}?limit=1");
        $responsePaginated->assertStatus(200);
        $this->assertCount(1, $responsePaginated->json('data'));
        $responsePaginated->assertJsonStructure([
            'pagination' => [
                'current_page',
                'last_page',
                'per_page',
                'total',
                'from',
                'to',
            ]
        ]);
        $this->assertEquals(2, $responsePaginated->json('pagination.total'));
    }
}

