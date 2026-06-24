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
use App\Models\ShiftWorkforceDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkforceDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private $adminUser;
    private $supervisorEmployee;
    private $siteInchargeEmployee;
    private $shiftA;
    private $shiftB;
    private $site;
    private $employee1;
    private $employee2;
    private $employee3;

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

        // Create Shifts & Site
        $this->shiftA = Shift::create([
            'shift_name' => 'A',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        $this->shiftB = Shift::create([
            'shift_name' => 'B',
            'start_time' => '16:00:00',
            'end_time' => '00:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        $this->site = Site::create([
            'site_name' => 'Block-04 West',
            'address' => 'Site Address',
            'is_active' => 1
        ]);

        // Create test employees
        $this->employee1 = Employee::create([
            'employee_code' => 'EMP-001',
            'name' => 'Ramesh Kumar',
            'joining_date' => '2026-01-01',
            'designation_id' => $supervisorRole->id,
            'is_active' => 1,
            'relay_shift' => 'relay_1'
        ]);

        $this->employee2 = Employee::create([
            'employee_code' => 'EMP-002',
            'name' => 'Suresh Yadav',
            'joining_date' => '2026-01-01',
            'designation_id' => $supervisorRole->id,
            'is_active' => 1,
            'relay_shift' => 'relay_2'
        ]);

        $this->employee3 = Employee::create([
            'employee_code' => 'EMP-003',
            'name' => 'Mahesh Sharma',
            'joining_date' => '2026-01-01',
            'designation_id' => $supervisorRole->id,
            'is_active' => 0, // Inactive employee for EF-02 test
            'relay_shift' => 'relay_3'
        ]);

        Sanctum::actingAs($this->adminUser);
    }

    public function test_can_load_relay_workforce_automatically_by_shift_id()
    {
        $planningDate = '2026-06-23';

        // Create shift plan
        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // Assign employee 1 to Shift A
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => $planningDate,
            'to_date' => null
        ]);

        // Assign employee 2 to Shift B
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => $planningDate,
            'to_date' => null
        ]);

        // POST /load-relay
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/load-relay");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals($this->employee1->id, $response->json('data.0.employee_id'));
    }

    public function test_available_employees_only_shows_those_assigned_to_other_shifts()
    {
        $planningDate = '2026-06-23';

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // Employee 1 on Shift B
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => $planningDate
        ]);

        // Employee 2 on Shift A
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => $planningDate
        ]);

        // GET /available-employees
        $response = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/available-employees");

        $response->assertStatus(200);
        
        // Should only show employee2 (since they are assigned to Shift A, which is != Shift B)
        // Should not show employee1 (same shift) or employee3 (inactive)
        $response->assertJsonCount(1, 'data');
        $this->assertEquals($this->employee2->id, $response->json('data.0.employee_id'));
    }

    public function test_available_employees_can_be_filtered_by_shift_id()
    {
        $planningDate = '2026-06-23';

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'planned',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // Employee 1 on Shift B
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => $planningDate
        ]);

        // Employee 2 on Shift A
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => $planningDate
        ]);

        // GET /available-employees?shift_id=X
        // Filter by Shift A
        $response = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/available-employees?shift_id={$this->shiftA->id}");
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals($this->employee2->id, $response->json('data.0.employee_id'));

        // Filter by Shift B (even though current shift plan is Shift B)
        $response = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/available-employees?shift_id={$this->shiftB->id}");
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $this->assertEquals($this->employee1->id, $response->json('data.0.employee_id'));
    }

    public function test_can_borrow_multiple_employees_from_other_shifts()
    {
        $planningDate = '2026-06-23';

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // Employee 2 on Shift A
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => $planningDate
        ]);

        // POST /borrow
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/borrow", [
            'employee_ids' => [$this->employee2->id],
            'borrowing_reason' => 'Need operator support'
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'status',
            'message',
            'data',
            'stats',
            'pagination'
        ]);
        $response->assertJsonCount(1, 'data');
        $this->assertTrue($response->json('data.0.is_borrowed'));
    }

    public function test_borrowing_enforces_active_status_and_date_uniqueness()
    {
        $planningDate = '2026-06-23';

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // 1. Employee 3 is inactive. Trying to borrow should fail validation (EF-02)
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/borrow", [
            'employee_ids' => [$this->employee3->id],
            'borrowing_reason' => 'Test'
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Selected Employee Is Not Available.']);

        // 2. Already deployed uniqueness check (EF-01)
        // First deploy employee 2
        ShiftWorkforceDeployment::create([
            'shift_plan_id' => $shiftPlan->id,
            'employee_id' => $this->employee2->id,
            'relay_shift' => 'relay_2',
            'is_borrowed' => false,
            'status' => 'active'
        ]);

        // Try to borrow employee 2 on the same date/shift
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/borrow", [
            'employee_ids' => [$this->employee2->id],
            'borrowing_reason' => 'Test'
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Employee Already Assigned To Shift: A']);

        // 3. Try to borrow employee 2 to a different shift plan on the same date (Shift B)
        $shiftPlanB = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-002'
        ]);

        $response2 = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanB->id}/workforce/borrow", [
            'employee_ids' => [$this->employee2->id],
            'borrowing_reason' => 'Test'
        ]);

        $response2->assertStatus(422);
        $response2->assertJsonFragment(['message' => 'Employee Already Assigned To Shift: A']);
    }

    public function test_can_soft_remove_workforce_deployment()
    {
        $planningDate = '2026-06-23';

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        $deployment = ShiftWorkforceDeployment::create([
            'shift_plan_id' => $shiftPlan->id,
            'employee_id' => $this->employee1->id,
            'relay_shift' => 'relay_1',
            'is_borrowed' => false,
            'status' => 'active'
        ]);

        $response = $this->deleteJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/{$deployment->id}", [
            'removed_reason' => 'Employee left early'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data',
            'stats' => [
                'planned',
                'present',
                'leave',
                'borrowed'
            ]
        ]);
        $this->assertEquals('removed', ShiftWorkforceDeployment::find($deployment->id)->status);
    }

    public function test_excludes_on_leave_absent_or_rest_day_employees()
    {
        $planningDate = '2026-06-23';

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-002'
        ]);

        // Employee 1 is on Shift A, but has an approved leave on this date
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => $planningDate
        ]);

        \App\Models\Leave::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => null,
            'from_date' => $planningDate,
            'to_date' => $planningDate,
            'status' => 'approved',
            'reason' => 'Sick leave'
        ]);

        // Employee 2 is on Shift B, but is marked as rest_day on this date in processed attendance
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => $planningDate
        ]);

        \App\Models\AttendanceProcessed::create([
            'employee_id' => $this->employee2->id,
            'date' => $planningDate,
            'attendance_status' => 'rest_day'
        ]);

        // 1. Auto-load: Should not load employee 1 because Ramesh is on leave
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/load-relay");
        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data'); // Ramesh is excluded because of approved leave

        // 2. Available for borrowing: Should not show employee 2 because Suresh is marked rest_day
        $response = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/available-employees");
        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data'); // Suresh is excluded because he is on rest_day

        // 3. Borrowing: Try to borrow employee 2 (rest_day) — should fail with specific rest day message
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/borrow", [
            'employee_ids' => [$this->employee2->id],
            'borrowing_reason' => 'Need support'
        ]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Employee is on rest day.']);

        // 4. Borrowing: Try to borrow employee 1 (leave) — should fail with specific leave message
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/borrow", [
            'employee_ids' => [$this->employee1->id],
            'borrowing_reason' => 'Need support'
        ]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Employee is on leave.']);
    }

    public function test_removed_employees_are_not_re_deployed_on_load_relay()
    {
        $planningDate = '2026-06-23';

        // Assign employee 1 to Shift A
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => $planningDate
        ]);

        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // 1. Auto-load — employee 1 should be deployed
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/load-relay");
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');

        // 2. Remove the deployment
        $deployment = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlan->id)
            ->where('employee_id', $this->employee1->id)
            ->first();

        $removeResponse = $this->deleteJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/{$deployment->id}", [
            'removed_reason' => 'Not needed today'
        ]);
        $removeResponse->assertStatus(200);
        $this->assertEquals('removed', ShiftWorkforceDeployment::find($deployment->id)->status);

        // 3. Run load-relay again — employee 1 should NOT be re-deployed
        $response2 = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/load-relay");
        $response2->assertStatus(200);

        // Should still have 0 active deployments (removed employee should not come back)
        $activeCount = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlan->id)
            ->active()
            ->count();
        $this->assertEquals(0, $activeCount);
    }

    public function test_can_get_workforce_list_with_stats()
    {
        $planningDate = '2026-06-23';

        // 1. Assign Employee 1 and Employee 2 to Shift B
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => $planningDate
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => $planningDate
        ]);

        // 2. Create Employee 4 (active) and assign to Shift A (available for borrowing)
        $employee4 = Employee::create([
            'employee_code' => 'EMP-004',
            'name' => 'Gaurav Rawat',
            'joining_date' => '2026-01-01',
            'designation_id' => $this->employee1->designation_id,
            'is_active' => 1,
            'relay_shift' => 'relay_3'
        ]);

        EmployeeShiftAssignment::create([
            'employee_id' => $employee4->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => $planningDate
        ]);

        // 3. Put Employee 2 on approved leave
        \App\Models\Leave::create([
            'employee_id' => $this->employee2->id,
            'leave_type_id' => null,
            'from_date' => $planningDate,
            'to_date' => $planningDate,
            'status' => 'approved',
            'reason' => 'Sick leave'
        ]);

        // 4. Create Shift Plan for Shift B
        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001'
        ]);

        // 5. Load relay (deploys Employee 1; Employee 2 on leave is skipped)
        $lrResponse = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/load-relay");

        // 6. Borrow Employee 4
        $bResponse = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/borrow", [
            'employee_ids' => [$employee4->id],
            'borrowing_reason' => 'Need support'
        ]);

        // 7. Get deployed employees list
        $response = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data',
            'stats' => [
                'planned',
                'present',
                'leave',
                'borrowed'
            ]
        ]);

        $stats = $response->json('stats');
        $this->assertEquals(2, $stats['planned']);  // Employee 1 & 2
        $this->assertEquals(1, $stats['present']);  // Employee 1
        $this->assertEquals(1, $stats['leave']);    // Employee 2
        $this->assertEquals(1, $stats['borrowed']); // Employee 4

        // 8. Remove the borrowed employee
        $borrowedDeployment = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlan->id)
            ->where('employee_id', $employee4->id)
            ->first();

        $removeResponse = $this->deleteJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/{$borrowedDeployment->id}", [
            'removed_reason' => 'Not needed anymore'
        ]);
        $removeResponse->assertStatus(200);

        // 9. Get list again and verify stats
        $response2 = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce");
        $response2->assertStatus(200);
        $stats2 = $response2->json('stats');
        $this->assertEquals(2, $stats2['planned']);
        $this->assertEquals(1, $stats2['present']);
        $this->assertEquals(1, $stats2['leave']);
        $this->assertEquals(1, $stats2['borrowed']); // Still 1 even though removed!
    }

    public function test_deployed_employee_who_goes_on_leave_is_excluded_from_present_and_list()
    {
        $planningDate = '2026-06-23';

        // 1. Assign Employee 1 to Shift A
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee1->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => $planningDate
        ]);

        // 2. Create Shift Plan for Shift A
        $shiftPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-002'
        ]);

        // 3. Load relay (deploys Employee 1)
        $this->postJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce/load-relay");

        // Verify Employee 1 is present in list and stats
        $response1 = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce");
        $response1->assertJsonCount(1, 'data');
        $this->assertEquals(1, $response1->json('stats.present'));
        $this->assertEquals(0, $response1->json('stats.leave'));

        // 4. Mark Employee 1 on approved leave
        \App\Models\Leave::create([
            'employee_id' => $this->employee1->id,
            'leave_type_id' => null,
            'from_date' => $planningDate,
            'to_date' => $planningDate,
            'status' => 'approved',
            'reason' => 'Sick leave'
        ]);

        // 5. Get list again — Employee 1 should NOT be in the list, present count is 0, leave count is 1
        $response2 = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/workforce");
        $response2->assertJsonCount(0, 'data');
        $this->assertEquals(0, $response2->json('stats.present'));
        $this->assertEquals(1, $response2->json('stats.leave'));
    }

    public function test_cannot_borrow_from_active_shift_plan()
    {
        $planningDate = '2026-06-23';

        // Target shift plan (Shift B)
        $targetPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'planned',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TARGET'
        ]);

        // Home shift plan (Shift A) - Active (working phase)
        $homePlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-HOME'
        ]);

        // Employee 2 assigned to Shift A
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftA->id,
            'from_date' => $planningDate
        ]);

        // Try to borrow from active shift plan
        $response = $this->postJson("/api/v1/admin/shift-plans/{$targetPlan->id}/workforce/borrow", [
            'employee_ids' => [$this->employee2->id],
            'borrowing_reason' => 'Test active'
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Cannot borrow employees from a shift that is already in the working phase.']);
    }

    public function test_cannot_borrow_from_shift_b_if_target_starts_before_b()
    {
        $planningDate = '2026-06-23';

        // Target shift plan (Shift A starts at 08:00:00)
        $targetPlan = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $this->shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'planned',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TARGET'
        ]);

        // Employee 2 assigned to Shift B (starts at 16:00:00)
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee2->id,
            'shift_id' => $this->shiftB->id,
            'from_date' => $planningDate
        ]);

        // Try to borrow from Shift B into Shift A (target starts before selected)
        $response = $this->postJson("/api/v1/admin/shift-plans/{$targetPlan->id}/workforce/borrow", [
            'employee_ids' => [$this->employee2->id],
            'borrowing_reason' => 'Test shift B time limit'
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Cannot borrow employees from B when the target shift starts before B.']);
    }
}
