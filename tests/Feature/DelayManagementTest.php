<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\ShiftPlan;
use App\Models\BreakdownTicket;
use App\Models\BreakdownType;
use App\Models\DelayCategory;
use App\Models\Delay;
use App\Models\DelayAuditLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DelayManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $supervisorUser;
    protected $supervisorEmployee;
    protected $siteInchargeUser;
    protected $siteInchargeEmployee;
    protected $otherUser;
    protected $shift;
    protected $site;
    protected $equipment;
    protected $equipmentName;
    protected $publishedShiftPlan;
    protected $closedShiftPlan;
    protected $breakdownType;
    protected $breakdownTicket;

    protected $rainCategory;
    protected $roadConditionCategory;
    protected $machineBreakdownCategory;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Roles
        $adminRole = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);
        $supervisorRole = Role::create(['name' => 'Supervisor', 'slug' => 'supervisor', 'is_active' => 1]);
        $siteInchargeRole = Role::create(['name' => 'Site Incharge', 'slug' => 'site_incharge', 'is_active' => 1]);
        $otherRole = Role::create(['name' => 'Other Role', 'slug' => 'other', 'is_active' => 1]);

        // Create Users
        $this->adminUser = User::create(['email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->adminUser->roles()->attach($adminRole);

        $this->supervisorUser = User::create(['email' => 'supervisor@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->supervisorUser->roles()->attach($supervisorRole);

        $this->siteInchargeUser = User::create(['email' => 'siteincharge@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->siteInchargeUser->roles()->attach($siteInchargeRole);

        $this->otherUser = User::create(['email' => 'other@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->otherUser->roles()->attach($otherRole);

        // Link Users to RoleUser and Employee
        $supRoleUser = RoleUser::create([
            'user_id' => $this->supervisorUser->id,
            'role_id' => $supervisorRole->id,
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
            'role_user_id' => $supRoleUser->id,
        ]);

        $incRoleUser = RoleUser::create([
            'user_id' => $this->siteInchargeUser->id,
            'role_id' => $siteInchargeRole->id,
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
            'designation_id' => $siteInchargeRole->id,
            'salary_type' => 'monthly',
            'basic_salary' => 50000,
            'is_active' => 1,
            'role_user_id' => $incRoleUser->id,
        ]);

        // Create Shift
        $this->shift = Shift::create([
            'shift_name'            => 'Day Shift',
            'start_time'            => '08:00:00',
            'end_time'              => '16:00:00',
            'minimum_working_hours' => 8,
            'is_night_shift'        => 0,
        ]);

        // Create Site
        $this->site = Site::create([
            'site_name' => 'Test Site',
            'is_active' => 1,
        ]);

        // Create Equipment (Category)
        $this->equipment = Equipment::create([
            'name'      => 'Excavator',
            'is_active' => 1,
        ]);

        // Create EquipmentName (Machine Instance)
        $this->equipmentName = EquipmentName::create([
            'equipment_id'   => $this->equipment->id,
            'equipment_name' => 'EX01-Excavator-CAT',
            'is_active'      => 1,
        ]);

        // Create Published Shift Plan
        $this->publishedShiftPlan = ShiftPlan::create([
            'planning_date' => '2026-06-27',
            'shift_id'      => $this->shift->id,
            'site_id'       => $this->site->id,
            'target_bcm'    => 1000,
            'status'        => 'published',
            'supervisor_id' => $this->supervisorUser->id,
            'site_incharge_id' => $this->siteInchargeUser->id,
            'created_by'    => $this->adminUser->id,
            'reference_no'  => 'SP-PUBLISHED-001',
        ]);

        // Create Closed Shift Plan
        $this->closedShiftPlan = ShiftPlan::create([
            'planning_date' => '2026-06-28',
            'shift_id'      => $this->shift->id,
            'site_id'       => $this->site->id,
            'target_bcm'    => 1000,
            'status'        => 'closed',
            'supervisor_id' => $this->supervisorUser->id,
            'site_incharge_id' => $this->siteInchargeUser->id,
            'created_by'    => $this->adminUser->id,
            'reference_no'  => 'SP-CLOSED-001',
        ]);

        // Create BreakdownType
        $this->breakdownType = BreakdownType::create([
            'breakdown_type' => 'Engine Breakdown',
            'description' => 'Engine issues',
            'is_active' => 1,
        ]);

        // Create Breakdown Ticket
        $this->breakdownTicket = BreakdownTicket::create([
            'ticket_number' => 'BT-2026-001',
            'shift_id' => $this->shift->id,
            'equipment_id' => $this->equipment->id,
            'equipment_name_id' => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-27 10:00:00',
            'reported_by' => $this->supervisorEmployee->id,
            'breakdown_type_id' => $this->breakdownType->id,
            'severity' => 'MEDIUM',
            'description' => 'Engine issues',
            'status' => 'OPEN',
        ]);

        // Create Delay Categories
        $this->rainCategory = DelayCategory::create([
            'delay_category' => 'Rain',
            'is_active' => 1,
        ]);

        $this->roadConditionCategory = DelayCategory::create([
            'delay_category' => 'Road Condition',
            'is_active' => 1,
        ]);

        $this->machineBreakdownCategory = DelayCategory::create([
            'delay_category' => 'Machine Breakdown',
            'is_active' => 1,
        ]);
    }

    public function test_supervisor_can_log_delay()
    {
        Sanctum::actingAs($this->supervisorUser);

        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '09:00:00',
            'end_time' => '10:30:00', // 90 minutes = 1.5 hours
            'severity' => 'MEDIUM',
            'description' => 'Heavy rain delayed operation',
        ];

        $response = $this->postJson('/api/v1/admin/delays', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 201)
            ->assertJsonPath('message', 'Delay logged successfully.')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'id',
                    'delay_ref_no',
                    'shift_plan_id',
                    'duration_minutes',
                    'estimated_production_loss_bcm',
                ]
            ]);

        // 1.5 hours * 150 BCM/hour = 225 BCM
        $this->assertEquals(90, $response->json('data.duration_minutes'));
        $this->assertEquals(225.00, $response->json('data.estimated_production_loss_bcm'));

        $this->assertDatabaseHas('delays', [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'delay_category_id' => $this->rainCategory->id,
            'duration_minutes' => 90,
            'estimated_production_loss_bcm' => 225.00,
        ]);
    }

    public function test_site_incharge_can_log_delay()
    {
        Sanctum::actingAs($this->siteInchargeUser);

        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'delay_category_id' => $this->roadConditionCategory->id,
            'start_time' => '11:00:00',
            'end_time' => '12:00:00', // 60 minutes = 1.0 hour
            'severity' => 'LOW',
            'description' => 'Slippery road due to light drizzle',
        ];

        $response = $this->postJson('/api/v1/admin/delays', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 201);

        $this->assertEquals(60, $response->json('data.duration_minutes'));
        $this->assertEquals(150.00, $response->json('data.estimated_production_loss_bcm'));
    }

    public function test_unauthorized_roles_cannot_log_delay()
    {
        // otherUser has role 'other', which is not in ['super-admin', 'supervisor', 'site_incharge']
        Sanctum::actingAs($this->otherUser);

        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '09:00:00',
            'severity' => 'MEDIUM',
            'description' => 'Heavy rain',
        ];

        $response = $this->postJson('/api/v1/admin/delays', $payload);
        $response->assertStatus(403);
    }

    public function test_machine_breakdown_requires_breakdown_details()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Missing breakdown attributes
        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'delay_category_id' => $this->machineBreakdownCategory->id,
            'start_time' => '09:00:00',
            'severity' => 'HIGH',
            'description' => 'Excavator failed',
        ];

        $response = $this->postJson('/api/v1/admin/delays', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['equipment_id', 'equipment_name_id']);

        // Now supply breakdown attributes
        $payload['linked_breakdown_id'] = $this->breakdownTicket->id;
        $payload['equipment_id'] = $this->equipment->id;
        $payload['equipment_name_id'] = $this->equipmentName->id;

        $response = $this->postJson('/api/v1/admin/delays', $payload);
        $response->assertStatus(201);
    }

    public function test_read_only_field_mutation_protection()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Create Delay
        $delay = Delay::create([
            'delay_ref_no' => 'DLY-2026-000001',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-06-27',
            'shift_name' => 'Day Shift',
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '09:00:00',
            'severity' => 'LOW',
            'description' => 'Rain',
            'created_by' => $this->supervisorUser->id,
        ]);

        // Create a different shift to pass
        $otherShift = Shift::create([
            'shift_name'            => 'Other Shift',
            'start_time'            => '08:00:00',
            'end_time'              => '16:00:00',
            'minimum_working_hours' => 8,
            'is_night_shift'        => 0,
        ]);

        $otherShiftPlan = ShiftPlan::create([
            'planning_date' => '2026-06-29',
            'shift_id'      => $otherShift->id,
            'site_id'       => $this->site->id,
            'target_bcm'    => 1000,
            'status'        => 'published',
            'supervisor_id' => $this->supervisorUser->id,
            'site_incharge_id' => $this->siteInchargeUser->id,
            'created_by'    => $this->adminUser->id,
            'reference_no'  => 'SP-PUBLISHED-999',
        ]);

        // Mutating fields should succeed now
        $response = $this->putJson("/api/v1/admin/delays/{$delay->id}", [
            'shift_plan_id' => $otherShiftPlan->id,
            'delay_ref_no' => 'DLY-2026-999999',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('delays', [
            'id' => $delay->id,
            'shift_plan_id' => $otherShiftPlan->id,
            'shift_id' => $otherShift->id,
            'shift_date' => '2026-06-29',
            'shift_name' => 'Other Shift',
            'delay_ref_no' => 'DLY-2026-999999',
        ]);
    }

    public function test_update_triggers_audit_logs()
    {
        Sanctum::actingAs($this->supervisorUser);

        $delay = Delay::create([
            'delay_ref_no' => 'DLY-2026-000002',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-06-27',
            'shift_name' => 'Day Shift',
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'duration_minutes' => 60,
            'average_production_rate_per_hour' => 150.00,
            'estimated_production_loss_bcm' => 150.00,
            'severity' => 'MEDIUM',
            'description' => 'Rain',
            'created_by' => $this->supervisorUser->id,
        ]);

        $response = $this->putJson("/api/v1/admin/delays/{$delay->id}", [
            'end_time' => '12:00:00',
            'description' => 'Heavy storm',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('delay_audit_logs', [
            'delay_id' => $delay->id,
            'changed_by' => $this->supervisorUser->id,
        ]);

        $auditLog = DelayAuditLog::where('delay_id', $delay->id)->first();
        $this->assertEquals('MEDIUM', $auditLog->old_values['severity']);
        $this->assertEquals('HIGH', $auditLog->new_values['severity']);
        $this->assertEquals('Rain', $auditLog->old_values['description']);
        $this->assertEquals('Heavy storm', $auditLog->new_values['description']);
    }

    public function test_closed_shift_block_edits_for_general_user()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Delay under closed shift plan
        $delay = Delay::create([
            'delay_ref_no' => 'DLY-2026-000003',
            'shift_plan_id' => $this->closedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-06-28',
            'shift_name' => 'Day Shift',
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '09:00:00',
            'severity' => 'MEDIUM',
            'description' => 'Rain',
            'created_by' => $this->supervisorUser->id,
        ]);

        // Attempting update must be blocked (403 Forbidden)
        $response = $this->putJson("/api/v1/admin/delays/{$delay->id}", [
            'description' => 'Updated rain description',
        ]);

        $response->assertStatus(403);
    }

    public function test_closed_shift_allows_edit_for_super_admin()
    {
        // Create delay on closed shift
        $delay = Delay::create([
            'delay_ref_no' => 'DLY-2026-000004',
            'shift_plan_id' => $this->closedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-06-28',
            'shift_name' => 'Day Shift',
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '09:00:00',
            'severity' => 'MEDIUM',
            'description' => 'Rain',
            'created_by' => $this->supervisorUser->id,
        ]);

        Sanctum::actingAs($this->adminUser); // Admin has super-admin role

        $response = $this->putJson("/api/v1/admin/delays/{$delay->id}", [
            'description' => 'Admin overriding description',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Admin overriding description', $response->json('data.description'));
    }

    public function test_view_delay_details()
    {
        Sanctum::actingAs($this->adminUser);

        $delay = Delay::create([
            'delay_ref_no' => 'DLY-2026-000005',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-06-27',
            'shift_name' => 'Day Shift',
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '09:00:00',
            'severity' => 'MEDIUM',
            'description' => 'Rain',
            'created_by' => $this->supervisorUser->id,
        ]);

        $response = $this->getJson("/api/v1/admin/delays/{$delay->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'id',
                    'delay_ref_no',
                    'shift_name',
                    'delay_category_id',
                    'delay_category_name',
                    'description',
                ]
            ]);
    }

    public function test_view_delay_register_with_filters_and_kpis()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Create two delays
        Delay::create([
            'delay_ref_no' => 'DLY-2026-000006',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-06-27',
            'shift_name' => 'Day Shift',
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00', // 60 mins
            'duration_minutes' => 60,
            'average_production_rate_per_hour' => 150.00,
            'estimated_production_loss_bcm' => 150.00,
            'severity' => 'MEDIUM',
            'description' => 'Rain 1',
            'created_by' => $this->supervisorUser->id,
        ]);

        Delay::create([
            'delay_ref_no' => 'DLY-2026-000007',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-06-27',
            'shift_name' => 'Day Shift',
            'delay_category_id' => $this->roadConditionCategory->id,
            'start_time' => '11:00:00',
            'end_time' => '13:00:00', // 120 mins
            'duration_minutes' => 120,
            'average_production_rate_per_hour' => 150.00,
            'estimated_production_loss_bcm' => 300.00,
            'severity' => 'HIGH',
            'description' => 'Road block',
            'created_by' => $this->supervisorUser->id,
        ]);

        // Request list with filters
        $response = $this->getJson("/api/v1/admin/delays?delay_category_id={$this->rainCategory->id}");

        $response->assertStatus(200)
            ->assertJsonPath('kpi_summary.total_delay_hours', 1)
            ->assertJsonPath('kpi_summary.total_production_loss_bcm', 150)
            ->assertJsonPath('kpi_summary.number_of_delay_events', 1)
            ->assertJsonPath('kpi_summary.largest_delay_reason.delay_category_name', $this->rainCategory->delay_category)
            ->assertJsonPath('kpi_summary.largest_delay_reason.total_delay_hours', 1)
            ->assertJsonStructure([
                'kpi_summary' => [
                    'category_summary' => [
                        '*' => [
                            'delay_category_id',
                            'delay_category_name',
                            'total_delay_hours',
                            'total_production_loss_bcm',
                            'number_of_delay_events',
                        ]
                    ]
                ]
            ]);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_storing_delay_with_explicit_shift_id_and_delay_log_date()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Create a different shift to pass
        $otherShift = Shift::create([
            'shift_name'            => 'Night Shift',
            'start_time'            => '20:00:00',
            'end_time'              => '04:00:00',
            'minimum_working_hours' => 8,
            'is_night_shift'        => 1,
        ]);

        // Create another shift plan referencing this other shift
        $otherShiftPlan = ShiftPlan::create([
            'planning_date' => '2026-06-29',
            'shift_id'      => $otherShift->id,
            'site_id'       => $this->site->id,
            'target_bcm'    => 1000,
            'status'        => 'published',
            'supervisor_id' => $this->supervisorUser->id,
            'site_incharge_id' => $this->siteInchargeUser->id,
            'created_by'    => $this->adminUser->id,
            'reference_no'  => 'SP-PUBLISHED-002',
        ]);

        $customLogDate = '2026-06-29 21:00:00';

        $payload = [
            'shift_plan_id' => $otherShiftPlan->id,
            'shift_id' => $otherShift->id,
            'delay_log_date' => $customLogDate,
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '21:00:00',
            'end_time' => '22:00:00',
            'severity' => 'LOW',
            'description' => 'Heavy storm',
        ];

        $response = $this->postJson('/api/v1/admin/delays', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.shift_id', $otherShift->id)
            ->assertJsonPath('data.delay_log_date', Carbon::parse($customLogDate)->toDateTimeString());

        $this->assertDatabaseHas('delays', [
            'shift_plan_id' => $otherShiftPlan->id,
            'shift_id' => $otherShift->id,
            'delay_log_date' => $customLogDate,
        ]);
    }

    public function test_storing_delay_fails_if_shift_id_does_not_match_shift_plan()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Create a mismatching shift id
        $mismatchShift = Shift::create([
            'shift_name'            => 'Evening Shift',
            'start_time'            => '16:00:00',
            'end_time'              => '22:00:00',
            'minimum_working_hours' => 6,
            'is_night_shift'        => 0,
        ]);

        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $mismatchShift->id, // does not match $this->publishedShiftPlan->shift_id
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '09:00:00',
            'severity' => 'MEDIUM',
            'description' => 'Heavy rain',
        ];

        $response = $this->postJson('/api/v1/admin/delays', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shift_id']);
    }

    public function test_storing_delay_fails_if_breakdown_not_linked_to_shift_plan()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Create a mismatching shift
        $otherShift = Shift::create([
            'shift_name'            => 'Other Shift',
            'start_time'            => '08:00:00',
            'end_time'              => '16:00:00',
            'minimum_working_hours' => 8,
            'is_night_shift'        => 0,
        ]);

        // Create a breakdown ticket for the other shift
        $mismatchBreakdown = BreakdownTicket::create([
            'ticket_number' => 'BT-MISMATCH-001',
            'shift_id' => $otherShift->id,
            'equipment_id' => $this->equipment->id,
            'equipment_name_id' => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-27 10:00:00',
            'reported_by' => $this->supervisorEmployee->id,
            'breakdown_type_id' => $this->breakdownType->id,
            'severity' => 'MEDIUM',
            'description' => 'Engine issues',
            'status' => 'OPEN',
        ]);

        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id, // shift_id is $this->shift->id
            'delay_category_id' => $this->machineBreakdownCategory->id,
            'linked_breakdown_id' => $mismatchBreakdown->id,
            'equipment_id' => $this->equipment->id,
            'equipment_name_id' => $this->equipmentName->id,
            'start_time' => '11:00:00',
            'severity' => 'MEDIUM',
            'description' => 'Breakdown delay',
        ];

        $response = $this->postJson('/api/v1/admin/delays', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['linked_breakdown_id']);
    }

    public function test_updating_delay_fails_if_breakdown_not_linked_to_shift_plan()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Create a valid delay first
        $delay = Delay::create([
            'delay_ref_no' => 'DEL-2026-999999',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-06-27',
            'shift_name' => 'Day Shift',
            'delay_category_id' => $this->machineBreakdownCategory->id,
            'start_time' => '10:00:00',
            'severity' => 'MEDIUM',
            'linked_breakdown_id' => $this->breakdownTicket->id,
            'equipment_id' => $this->equipment->id,
            'equipment_name_id' => $this->equipmentName->id,
            'description' => 'Breakdown delay',
            'created_by' => $this->supervisorUser->id,
        ]);

        // Create a mismatching shift
        $otherShift = Shift::create([
            'shift_name'            => 'Other Shift',
            'start_time'            => '08:00:00',
            'end_time'              => '16:00:00',
            'minimum_working_hours' => 8,
            'is_night_shift'        => 0,
        ]);

        // Create a breakdown ticket for the other shift
        $mismatchBreakdown = BreakdownTicket::create([
            'ticket_number' => 'BT-MISMATCH-002',
            'shift_id' => $otherShift->id,
            'equipment_id' => $this->equipment->id,
            'equipment_name_id' => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-27 10:00:00',
            'reported_by' => $this->supervisorEmployee->id,
            'breakdown_type_id' => $this->breakdownType->id,
            'severity' => 'MEDIUM',
            'description' => 'Engine issues',
            'status' => 'OPEN',
        ]);

        $payload = [
            'linked_breakdown_id' => $mismatchBreakdown->id,
        ];

        $response = $this->putJson("/api/v1/admin/delays/{$delay->id}", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['linked_breakdown_id']);
    }

    public function test_updating_delay_fails_if_shift_id_does_not_match_shift_plan()
    {
        Sanctum::actingAs($this->supervisorUser);

        $delay = Delay::create([
            'delay_ref_no' => 'DEL-2026-999998',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-06-27',
            'shift_name' => 'Day Shift',
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '10:00:00',
            'severity' => 'LOW',
            'description' => 'Rain delay',
            'created_by' => $this->supervisorUser->id,
        ]);

        $mismatchShift = Shift::create([
            'shift_name'            => 'Other Shift',
            'start_time'            => '08:00:00',
            'end_time'              => '16:00:00',
            'minimum_working_hours' => 8,
            'is_night_shift'        => 0,
        ]);

        $response = $this->putJson("/api/v1/admin/delays/{$delay->id}", [
            'shift_id' => $mismatchShift->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shift_id']);
    }

    public function test_updating_delay_fails_if_delay_log_date_does_not_match_shift_plan_date()
    {
        Sanctum::actingAs($this->supervisorUser);

        $delay = Delay::create([
            'delay_ref_no' => 'DEL-2026-999997',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-06-27',
            'shift_name' => 'Day Shift',
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '10:00:00',
            'severity' => 'LOW',
            'description' => 'Rain delay',
            'created_by' => $this->supervisorUser->id,
        ]);

        $response = $this->putJson("/api/v1/admin/delays/{$delay->id}", [
            'delay_log_date' => '2026-06-29 10:00:00', // mismatched date (2026-06-29 vs 2026-06-27)
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['delay_log_date']);
    }

    public function test_storing_delay_fails_if_delay_log_date_does_not_match_shift_plan_date()
    {
        Sanctum::actingAs($this->supervisorUser);

        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'delay_log_date' => '2026-06-29 10:00:00', // mismatched date
            'delay_category_id' => $this->rainCategory->id,
            'start_time' => '10:00:00',
            'description' => 'Rain delay',
        ];

        $response = $this->postJson('/api/v1/admin/delays', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['delay_log_date']);
    }
}
