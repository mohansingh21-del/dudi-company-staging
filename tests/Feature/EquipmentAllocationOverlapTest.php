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

class EquipmentAllocationOverlapTest extends TestCase
{
    use RefreshDatabase;

    private $adminUser;
    private $supervisorEmployee;
    private $siteInchargeEmployee;
    private $site;
    private $excavatorCategory;
    private $machine;

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

        $this->site = Site::create([
            'site_name' => 'Block-04 West',
            'address' => 'Site Address',
            'is_active' => 1
        ]);

        // Create category and machine
        $this->excavatorCategory = Equipment::create(['name' => 'Excavator', 'is_active' => 1]);
        $this->machine = EquipmentName::create([
            'equipment_id' => $this->excavatorCategory->id,
            'equipment_name' => 'EX-01',
            'is_active' => 1,
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($this->adminUser);
    }

    public function test_cannot_allocate_equipment_if_overlapping_times()
    {
        $planningDate = '2026-06-24';

        // Shift A: 10:00 to 13:00
        $shiftA = Shift::create([
            'shift_name' => 'Shift A',
            'start_time' => '10:00:00',
            'end_time' => '13:00:00',
            'minimum_working_hours' => 3.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Shift Plan A
        $planA = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-A'
        ]);

        // Allocate machine to Shift Plan A
        $response = $this->postJson("/api/v1/admin/shift-plans/{$planA->id}/equipment", [
            'machine_id' => $this->machine->id,
        ]);
        $response->assertStatus(201);

        // Shift B: 12:00 to 15:00 (overlaps with Shift A)
        $shiftB = Shift::create([
            'shift_name' => 'Shift B',
            'start_time' => '12:00:00',
            'end_time' => '15:00:00',
            'minimum_working_hours' => 3.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Shift Plan B
        $planB = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-B'
        ]);

        // Attempt to allocate the same machine to Shift Plan B -> Should fail because they overlap
        $response = $this->postJson("/api/v1/admin/shift-plans/{$planB->id}/equipment", [
            'machine_id' => $this->machine->id,
        ]);

        $response->assertStatus(409);
        $response->assertJsonFragment([
            'message' => 'Machine Already Allocated To Another Active Shift.'
        ]);
    }

    public function test_can_allocate_equipment_if_adjacent_times()
    {
        $planningDate = '2026-06-24';

        // Shift A: 10:00 to 13:00
        $shiftA = Shift::create([
            'shift_name' => 'Shift A',
            'start_time' => '10:00:00',
            'end_time' => '13:00:00',
            'minimum_working_hours' => 3.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Shift Plan A
        $planA = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-A'
        ]);

        // Allocate machine to Shift Plan A
        $response = $this->postJson("/api/v1/admin/shift-plans/{$planA->id}/equipment", [
            'machine_id' => $this->machine->id,
        ]);
        $response->assertStatus(201);

        // Shift B: 13:00 to 15:00 (ends at 13:00, starts at 13:00 -> adjacent, no overlap)
        $shiftB = Shift::create([
            'shift_name' => 'Shift B',
            'start_time' => '13:00:00',
            'end_time' => '15:00:00',
            'minimum_working_hours' => 2.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Shift Plan B
        $planB = ShiftPlan::create([
            'planning_date' => $planningDate,
            'shift_id' => $shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-B'
        ]);

        // Attempt to allocate the same machine to Shift Plan B -> Should succeed
        $response = $this->postJson("/api/v1/admin/shift-plans/{$planB->id}/equipment", [
            'machine_id' => $this->machine->id,
        ]);

        $response->assertStatus(201);
    }

    public function test_cannot_allocate_equipment_if_overlapping_cross_midnight()
    {
        // Shift A: 22:00 to 06:00 (cross-midnight, starting June 24)
        $shiftA = Shift::create([
            'shift_name' => 'Shift A',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 1,
            'is_active' => 1
        ]);

        // Shift Plan A
        $planA = ShiftPlan::create([
            'planning_date' => '2026-06-24',
            'shift_id' => $shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-A'
        ]);

        // Allocate machine to Shift Plan A
        $response = $this->postJson("/api/v1/admin/shift-plans/{$planA->id}/equipment", [
            'machine_id' => $this->machine->id,
        ]);
        $response->assertStatus(201);

        // Shift B: 05:00 to 08:00 (starts June 25 at 05:00, overlaps with Shift A's end at 06:00)
        $shiftB = Shift::create([
            'shift_name' => 'Shift B',
            'start_time' => '05:00:00',
            'end_time' => '08:00:00',
            'minimum_working_hours' => 3.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Shift Plan B
        $planB = ShiftPlan::create([
            'planning_date' => '2026-06-25',
            'shift_id' => $shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-B'
        ]);

        // Attempt to allocate the same machine to Shift Plan B -> Should fail because they overlap
        $response = $this->postJson("/api/v1/admin/shift-plans/{$planB->id}/equipment", [
            'machine_id' => $this->machine->id,
        ]);

        $response->assertStatus(409);
        $response->assertJsonFragment([
            'message' => 'Machine Already Allocated To Another Active Shift.'
        ]);
    }

    public function test_can_allocate_equipment_if_adjacent_cross_midnight()
    {
        // Shift A: 22:00 to 06:00 (cross-midnight, starting June 24)
        $shiftA = Shift::create([
            'shift_name' => 'Shift A',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 1,
            'is_active' => 1
        ]);

        // Shift Plan A
        $planA = ShiftPlan::create([
            'planning_date' => '2026-06-24',
            'shift_id' => $shiftA->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-A'
        ]);

        // Allocate machine to Shift Plan A
        $response = $this->postJson("/api/v1/admin/shift-plans/{$planA->id}/equipment", [
            'machine_id' => $this->machine->id,
        ]);
        $response->assertStatus(201);

        // Shift B: 06:00 to 10:00 (starts June 25 at 06:00 -> adjacent to Shift A's end at 06:00)
        $shiftB = Shift::create([
            'shift_name' => 'Shift B',
            'start_time' => '06:00:00',
            'end_time' => '10:00:00',
            'minimum_working_hours' => 4.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Shift Plan B
        $planB = ShiftPlan::create([
            'planning_date' => '2026-06-25',
            'shift_id' => $shiftB->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'draft',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-B'
        ]);

        // Attempt to allocate the same machine to Shift Plan B -> Should succeed
        $response = $this->postJson("/api/v1/admin/shift-plans/{$planB->id}/equipment", [
            'machine_id' => $this->machine->id,
        ]);

        $response->assertStatus(201);
    }
}
