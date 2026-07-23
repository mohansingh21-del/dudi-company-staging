<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Shift;
use App\Models\IncidentType;
use App\Models\Site;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\Employee;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncidentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $workerUser;
    protected $shift;
    protected $incidentType;
    protected $site;
    protected $equipment;
    protected $equipmentName;
    protected $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Roles
        $adminRole = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);
        $workerRole = Role::create(['name' => 'Worker', 'slug' => 'worker', 'is_active' => 1]);

        // Create Users
        $this->adminUser = User::create(['name' => 'Admin User', 'email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->adminUser->roles()->attach($adminRole);

        $this->workerUser = User::create(['name' => 'Worker User', 'email' => 'worker@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->workerUser->roles()->attach($workerRole);

        // Seed Entities
        $this->shift = Shift::create([
            'shift_name' => 'Day Shift',
            'start_time' => '08:00:00',
            'end_time'   => '16:00:00',
            'is_active'  => 1
        ]);

        $this->incidentType = IncidentType::create([
            'incident_type' => 'Unsafe Act',
            'description'   => 'Unsafe behavior on site',
            'is_active'     => 1
        ]);

        $this->site = Site::create([
            'site_name' => 'Pit A',
            'is_active' => 1
        ]);

        $this->equipment = Equipment::create([
            'name'      => 'Excavator',
            'is_active' => 1
        ]);

        $this->equipmentName = EquipmentName::create([
            'equipment_id'   => $this->equipment->id,
            'equipment_name' => 'EXC-01',
            'is_active'      => 1
        ]);

        $this->employee = Employee::create([
            'employee_code' => 'EMP-001',
            'name'          => 'John Doe',
            'dob'           => '1990-01-01',
            'gender'        => 'male',
            'mobile'        => '1234567890',
            'joining_date'  => '2026-01-01',
            'employee_type' => 'permanent',
            'salary_type'   => 'monthly',
            'basic_salary'  => 30000,
            'is_active'     => 1
        ]);
    }

    public function test_incident_bulk_import_route_accessible()
    {
        // Try calling without authenticating
        $this->postJson('/api/v1/admin/incidents/import', [])->assertStatus(401);

        // Try calling with non-admin/non-supervisor role
        Sanctum::actingAs($this->workerUser);
        $this->postJson('/api/v1/admin/incidents/import', [])->assertStatus(403);

        // Try calling with admin (should validate and return 422 due to empty request)
        Sanctum::actingAs($this->adminUser);
        $this->postJson('/api/v1/admin/incidents/import', [])->assertStatus(422);
    }

    public function test_incident_import_logic_success()
    {
        Sanctum::actingAs($this->adminUser);

        // Create Shift Plan
        $shiftPlan = \App\Models\ShiftPlan::create([
            'planning_date' => '2026-07-16',
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-INC-001',
        ]);

        // Allocate machine to Shift Plan
        \App\Models\ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $this->equipmentName->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        $rows = collect([
            [
                'incident_date'        => '16/07/2026',
                'shift_name'           => 'Day Shift',
                'incident_type'        => 'Unsafe Act',
                'severity'             => 'MEDIUM',
                'site_name'            => 'Pit A',
                'machine_category'     => 'Excavator',
                'machine_name'         => 'EXC-01',
                'employee_code'        => 'EMP-001',
                'incident_description' => 'Operator not wearing safety harness while working.',
                'action_taken'         => 'Stopped work and provided safety briefing.',
                'preventive_measures'  => 'Enforced daily safety harness checks.',
            ]
        ]);

        $import = new \App\Imports\IncidentImport();
        $import->collection($rows);

        $this->assertEquals(1, $import->getSuccessCount());
        $this->assertCount(0, $import->getErrors());

        $this->assertDatabaseHas('incidents', [
            'shift_id'             => $this->shift->id,
            'shift_plan_id'        => $shiftPlan->id,
            'incident_type_id'     => $this->incidentType->id,
            'severity'             => 'MEDIUM',
            'location_id'          => $this->site->id,
            'equipment_id'         => $this->equipment->id,
            'equipment_name_id'    => $this->equipmentName->id,
            'person_involved_id'   => $this->employee->id,
            'incident_description' => 'Operator not wearing safety harness while working.',
            'action_taken'         => 'Stopped work and provided safety briefing.',
            'preventive_measures'  => 'Enforced daily safety harness checks.',
            'status'               => 'Under Review',
        ]);
    }

    public function test_incident_import_logic_validation_errors()
    {
        Sanctum::actingAs($this->adminUser);

        $rows = collect([
            [
                'incident_date'        => '18/07/2030', // future date
                'shift_name'           => 'Invalid Shift',
                'incident_type'        => 'Invalid Type',
                'severity'             => 'CRITICALL', // invalid severity spelling
                'site_name'            => 'Invalid Site',
                'machine_category'     => 'Invalid Category',
                'machine_name'         => 'EXC-01',
                'employee_code'        => 'EMP-999', // invalid code
                'incident_description' => '', // empty
                'action_taken'         => '', // empty
            ]
        ]);

        $import = new \App\Imports\IncidentImport();
        $import->collection($rows);

        $this->assertEquals(0, $import->getSuccessCount());

        $errors = $import->getErrors();
        $this->assertGreaterThan(0, count($errors));

        // Assert all validation errors collected at once
        $this->assertTrue(collect($errors)->contains(fn($e) => str_contains($e, 'Incident date cannot be a future date.')));
        $this->assertTrue(collect($errors)->contains(fn($e) => str_contains($e, "Shift with name 'Invalid Shift' not found.")));
        $this->assertTrue(collect($errors)->contains(fn($e) => str_contains($e, "Incident Type 'Invalid Type' not found or inactive.")));
        $this->assertTrue(collect($errors)->contains(fn($e) => str_contains($e, "Invalid severity 'CRITICALL'.")));
        $this->assertTrue(collect($errors)->contains(fn($e) => str_contains($e, "Site with name 'Invalid Site' not found.")));
        $this->assertTrue(collect($errors)->contains(fn($e) => str_contains($e, "Machine Category 'Invalid Category' not found.")));
        $this->assertTrue(collect($errors)->contains(fn($e) => str_contains($e, "Employee with code 'EMP-999' not found.")));
        $this->assertTrue(collect($errors)->contains(fn($e) => str_contains($e, 'Incident Description is required.')));
        $this->assertTrue(collect($errors)->contains(fn($e) => str_contains($e, 'Action Taken is required.')));
    }

    public function test_incident_import_fails_if_machine_not_assigned_to_shift_plan()
    {
        Sanctum::actingAs($this->adminUser);

        // Scenario 1: No Shift Plan at all
        $rows = collect([
            [
                'incident_date'        => '16/07/2026',
                'shift_name'           => 'Day Shift',
                'incident_type'        => 'Unsafe Act',
                'severity'             => 'MEDIUM',
                'site_name'            => 'Pit A',
                'machine_category'     => 'Excavator',
                'machine_name'         => 'EXC-01',
                'employee_code'        => 'EMP-001',
                'incident_description' => 'Operator not wearing safety harness while working.',
                'action_taken'         => 'Stopped work and provided safety briefing.',
            ]
        ]);

        $import1 = new \App\Imports\IncidentImport();
        $import1->collection($rows);
        $this->assertEquals(0, $import1->getSuccessCount());
        $this->assertTrue(collect($import1->getErrors())->contains(fn($e) => str_contains($e, "No active shift plan found for date 16/07/2026, shift 'Day Shift', and site 'Pit A'.")));

        // Scenario 2: Shift Plan exists but machine not allocated
        $shiftPlan = \App\Models\ShiftPlan::create([
            'planning_date' => '2026-07-16',
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-INC-002',
        ]);

        $import2 = new \App\Imports\IncidentImport();
        $import2->collection($rows);
        $this->assertEquals(0, $import2->getSuccessCount());
        $this->assertTrue(collect($import2->getErrors())->contains(fn($e) => str_contains($e, "Machine 'EXC-01' is not assigned to the shift plan for date 16/07/2026, shift 'Day Shift', and site 'Pit A'.")));
    }

    public function test_api_store_incident_fails_if_machine_not_assigned_to_shift_plan()
    {
        Sanctum::actingAs($this->adminUser);

        // Try calling without shift plan
        $response = $this->postJson('/api/v1/admin/incidents', [
            'incident_date' => '16/07/2026',
            'shift_id' => $this->shift->id,
            'incident_type_id' => $this->incidentType->id,
            'severity' => 'MEDIUM',
            'location_id' => $this->site->id,
            'equipment_id' => $this->equipment->id,
            'equipment_name_id' => $this->equipmentName->id,
            'incident_description' => 'Test description',
            'action_taken' => 'Test action'
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['equipment_name_id']);

        // Now create a Shift Plan but do not allocate machine
        $shiftPlan = \App\Models\ShiftPlan::create([
            'planning_date' => '2026-07-16',
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-INC-API-001',
        ]);

        $response = $this->postJson('/api/v1/admin/incidents', [
            'incident_date' => '16/07/2026',
            'shift_id' => $this->shift->id,
            'incident_type_id' => $this->incidentType->id,
            'severity' => 'MEDIUM',
            'location_id' => $this->site->id,
            'equipment_id' => $this->equipment->id,
            'equipment_name_id' => $this->equipmentName->id,
            'incident_description' => 'Test description',
            'action_taken' => 'Test action'
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['equipment_name_id']);
    }

    public function test_api_store_incident_succeeds_if_machine_assigned_to_shift_plan()
    {
        Sanctum::actingAs($this->adminUser);

        $shiftPlan = \App\Models\ShiftPlan::create([
            'planning_date' => '2026-07-16',
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-INC-API-002',
        ]);

        // Allocate machine to Shift Plan
        \App\Models\ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $this->equipmentName->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        $response = $this->postJson('/api/v1/admin/incidents', [
            'incident_date' => '16/07/2026',
            'shift_id' => $this->shift->id,
            'incident_type_id' => $this->incidentType->id,
            'severity' => 'MEDIUM',
            'location_id' => $this->site->id,
            'equipment_id' => $this->equipment->id,
            'equipment_name_id' => $this->equipmentName->id,
            'incident_description' => 'Test description',
            'action_taken' => 'Test action'
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('incidents', [
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $this->equipmentName->id
        ]);
    }

    public function test_api_store_incident_validation_handles_invalid_date_format_gracefully()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson('/api/v1/admin/incidents', [
            'incident_date' => 'invalid-date-format',
            'shift_id' => $this->shift->id,
            'incident_type_id' => $this->incidentType->id,
            'severity' => 'MEDIUM',
            'location_id' => $this->site->id,
            'equipment_id' => $this->equipment->id,
            'equipment_name_id' => $this->equipmentName->id,
            'incident_description' => 'Test description',
            'action_taken' => 'Test action'
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['incident_date']);
    }

    public function test_api_store_incident_with_valid_datetime()
    {
        Sanctum::actingAs($this->adminUser);

        $shiftPlan = \App\Models\ShiftPlan::create([
            'planning_date' => '2026-07-16',
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-INC-API-003',
        ]);

        \App\Models\ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $this->equipmentName->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        $response = $this->postJson('/api/v1/admin/incidents', [
            'incident_date' => '16/07/2026 14:35:10',
            'shift_id' => $this->shift->id,
            'incident_type_id' => $this->incidentType->id,
            'severity' => 'MEDIUM',
            'location_id' => $this->site->id,
            'equipment_id' => $this->equipment->id,
            'equipment_name_id' => $this->equipmentName->id,
            'incident_description' => 'Test description with datetime',
            'action_taken' => 'Test action'
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('incidents', [
            'incident_date' => '2026-07-16 14:35:10',
            'shift_plan_id' => $shiftPlan->id,
        ]);
    }
}

