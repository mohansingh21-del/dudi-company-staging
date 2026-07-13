<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use App\Models\Role;
use App\Models\User;
use App\Models\RoleUser;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Site;
use App\Models\Shift;
use App\Models\SitePoint;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\ShiftPlan;
use App\Models\ShiftEquipmentAllocation;
use App\Models\ShiftWorkforceDeployment;
use App\Models\FuelEntry;
use App\Models\BreakdownTicket;
use App\Models\Delay;
use App\Models\DelayCategory;
use Carbon\Carbon;

class ShiftPlanningToDispatchFlowTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $supervisorUser;
    protected $supervisorEmp;
    protected $inchargeUser;
    protected $inchargeEmp;
    protected $drivers = [];
    protected $driverUsers = [];
    protected $operators = [];
    protected $operatorUsers = [];
    protected $site;
    protected $shift;
    protected $loadingPoint;
    protected $dumpingPoint;
    protected $excavatorCat;
    protected $dumperCat;
    protected $exc1Name;
    protected $exc2Name;
    protected $dmp1Name;
    protected $dmp2Name;
    protected $dmp3Name;
    protected $delayCategory;
    protected $breakdownType;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Roles
        $superAdminRole = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);
        $supervisorRole = Role::create(['name' => 'Supervisor', 'slug' => 'supervisor', 'is_active' => 1]);
        $inchargeRole = Role::create(['name' => 'Site Incharge', 'slug' => 'site-incharge', 'is_active' => 1]);
        $driverRole = Role::create(['name' => 'Driver', 'slug' => 'driver', 'is_active' => 1]);
        $workerRole = Role::create(['name' => 'Worker', 'slug' => 'worker', 'is_active' => 1]);

        // 2. Users
        $this->adminUser = User::create(['email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->adminUser->roles()->attach($superAdminRole);

        $this->supervisorUser = User::create(['email' => 'supervisor@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->supervisorUser->roles()->attach($supervisorRole);

        $this->inchargeUser = User::create(['email' => 'incharge@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->inchargeUser->roles()->attach($inchargeRole);

        // 3. Employees
        $this->supervisorEmp = Employee::create([
            'employee_code' => 'EMP-SUP',
            'name' => 'Shift Supervisor',
            'joining_date' => '2026-01-01',
            'is_active' => true,
            'designation_id' => $supervisorRole->id,
            'role_user_id' => RoleUser::create(['user_id' => $this->supervisorUser->id, 'role_id' => $supervisorRole->id])->id,
        ]);

        $this->inchargeEmp = Employee::create([
            'employee_code' => 'EMP-INC',
            'name' => 'Site Incharge',
            'joining_date' => '2026-01-01',
            'is_active' => true,
            'designation_id' => $inchargeRole->id,
            'role_user_id' => RoleUser::create(['user_id' => $this->inchargeUser->id, 'role_id' => $inchargeRole->id])->id,
        ]);

        // 3 Drivers
        for ($i = 1; $i <= 3; $i++) {
            $driverUser = User::create(['email' => "driver{$i}@test.com", 'password' => bcrypt('password'), 'is_active' => 1]);
            $this->driverUsers[$i] = $driverUser;
            $ru = RoleUser::create(['user_id' => $driverUser->id, 'role_id' => $driverRole->id]);
            $this->drivers[$i] = Employee::create([
                'employee_code' => "EMP-D{$i}",
                'name' => "Driver {$i}",
                'joining_date' => '2026-01-01',
                'is_active' => true,
                'designation_id' => $driverRole->id,
                'role_user_id' => $ru->id,
            ]);
        }

        // 2 Operators
        for ($i = 1; $i <= 2; $i++) {
            $opUser = User::create(['email' => "operator{$i}@test.com", 'password' => bcrypt('password'), 'is_active' => 1]);
            $this->operatorUsers[$i] = $opUser;
            $ru = RoleUser::create(['user_id' => $opUser->id, 'role_id' => $workerRole->id]);
            $this->operators[$i] = Employee::create([
                'employee_code' => "EMP-O{$i}",
                'name' => "Operator {$i}",
                'joining_date' => '2026-01-01',
                'is_active' => true,
                'designation_id' => $workerRole->id,
                'role_user_id' => $ru->id,
            ]);
        }

        // 4. Sites
        $this->site = Site::create(['site_name' => 'Site Alpha', 'is_active' => true]);

        // 5. Shifts
        $this->shift = Shift::create([
            'shift_name' => 'Day Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'is_night_shift' => 0,
            'minimum_working_hours' => 8,
        ]);

        // 6. Site Points
        $this->loadingPoint = SitePoint::create([
            'site_id' => $this->site->id,
            'name' => 'LP-1',
            'type' => 'loading',
            'is_active' => true,
            'created_by' => $this->adminUser->id,
        ]);

        $this->dumpingPoint = SitePoint::create([
            'site_id' => $this->site->id,
            'name' => 'DP-1',
            'type' => 'dumping',
            'is_active' => true,
            'created_by' => $this->adminUser->id,
        ]);

        // 7. Equipment Categories & Instances
        $this->excavatorCat = Equipment::create(['name' => 'Excavator', 'is_active' => true]);
        $this->dumperCat = Equipment::create(['name' => 'Dumper', 'is_active' => true]);

        $this->exc1Name = EquipmentName::create(['equipment_name' => 'EXC-01', 'equipment_id' => $this->excavatorCat->id, 'is_active' => true]);
        $this->exc2Name = EquipmentName::create(['equipment_name' => 'EXC-02', 'equipment_id' => $this->excavatorCat->id, 'is_active' => true]);

        $this->dmp1Name = EquipmentName::create(['equipment_name' => 'DMP-01', 'equipment_id' => $this->dumperCat->id, 'is_active' => true]);
        $this->dmp2Name = EquipmentName::create(['equipment_name' => 'DMP-02', 'equipment_id' => $this->dumperCat->id, 'is_active' => true]);
        $this->dmp3Name = EquipmentName::create(['equipment_name' => 'DMP-03', 'equipment_id' => $this->dumperCat->id, 'is_active' => true]);

        // 8. Employee Shift Assignments
        foreach (array_merge([$this->supervisorEmp, $this->inchargeEmp], $this->drivers, $this->operators) as $emp) {
            EmployeeShiftAssignment::create([
                'employee_id' => $emp->id,
                'shift_id' => $this->shift->id,
                'from_date' => '2026-06-01',
                'to_date' => null,
            ]);
        }

        // 9. Delay Category
        $this->delayCategory = DelayCategory::create([
            'delay_category' => 'Operational Delay',
            'description' => 'Delays during operational shift',
            'is_active' => true,
        ]);

        // 10. Breakdown Type
        $this->breakdownType = \App\Models\BreakdownType::create([
            'breakdown_type' => 'Mechanical Breakdown',
            'description' => 'Mechanical issues',
            'is_active' => 1,
        ]);

        // Authenticate admin user
        Sanctum::actingAs($this->adminUser);
    }

    public function test_full_mining_shift_lifecycle_flow()
    {
        $planningDate = '2026-07-02';

        // ── 1. Create Shift Plan in Draft Status ──────────────────────
        $response = $this->postJson('/api/v1/admin/shift-plans', [
            'planning_date' => $planningDate,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1500.00,
            'supervisor_id' => $this->supervisorEmp->id,
            'site_incharge_id' => $this->inchargeEmp->id,
        ]);

        $response->assertStatus(201);
        $shiftPlanId = $response->json('data.id');
        $this->assertDatabaseHas('shift_plans', [
            'id' => $shiftPlanId,
            'status' => 'draft',
            'equipment_count' => 0,
        ]);

        // ── 2. Allocate Equipment (Nesting Dumpers under Excavators) ──
        // (a) Allocate EXC-01
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/equipment", [
            'machine_id' => $this->exc1Name->id,
            'parent_category_id' => null,
        ]);
        $response->assertStatus(201);
        $allocExc1Id = $response->json('data.allocation_id');

        // (b) Allocate EXC-02
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/equipment", [
            'machine_id' => $this->exc2Name->id,
            'parent_category_id' => null,
        ]);
        $response->assertStatus(201);
        $allocExc2Id = $response->json('data.allocation_id');

        // (c) Allocate DMP-01 under EXC-01
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/equipment", [
            'machine_id' => $this->dmp1Name->id,
            'parent_category_id' => $this->exc1Name->id,
        ]);
        $response->assertStatus(201);
        $allocDmp1Id = $response->json('data.allocation_id');

        // (d) Allocate DMP-02 under EXC-01
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/equipment", [
            'machine_id' => $this->dmp2Name->id,
            'parent_category_id' => $this->exc1Name->id,
        ]);
        $response->assertStatus(201);
        $allocDmp2Id = $response->json('data.allocation_id');

        // (e) Allocate DMP-03 under EXC-02
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/equipment", [
            'machine_id' => $this->dmp3Name->id,
            'parent_category_id' => $this->exc2Name->id,
        ]);
        $response->assertStatus(201);
        $allocDmp3Id = $response->json('data.allocation_id');

        // Assert equipment count is updated to 5
        $this->assertDatabaseHas('shift_plans', [
            'id' => $shiftPlanId,
            'equipment_count' => 5,
        ]);

        // Get and check equipment listing
        $response = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlanId}/equipment");
        $response->assertStatus(200)
            ->assertJsonFragment(['machine_number' => 'EXC-01'])
            ->assertJsonFragment(['machine_number' => 'DMP-01']);

        // ── 3. Load Workforce Deployments ─────────────────────────────
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/workforce/load-relay");
        $response->assertStatus(200);

        // Assign machines to deployments in database (simulating supervisors link driver to equipment)
        $deployments = ShiftWorkforceDeployment::where('shift_plan_id', $shiftPlanId)->get();
        foreach ($deployments as $dep) {
            if ($dep->employee_id === $this->operators[1]->id) {
                $dep->update(['assigned_machine_id' => $allocExc1Id]);
            } elseif ($dep->employee_id === $this->operators[2]->id) {
                $dep->update(['assigned_machine_id' => $allocExc2Id]);
            } elseif ($dep->employee_id === $this->drivers[1]->id) {
                $dep->update(['assigned_machine_id' => $allocDmp1Id]);
            } elseif ($dep->employee_id === $this->drivers[2]->id) {
                $dep->update(['assigned_machine_id' => $allocDmp2Id]);
            } elseif ($dep->employee_id === $this->drivers[3]->id) {
                $dep->update(['assigned_machine_id' => $allocDmp3Id]);
            }
        }

        // ── 4. Publish and Activate Shift Plan ────────────────────────
        // (a) Publish shift plan
        $response = $this->postJson("/api/v1/admin/shift-plans/{$shiftPlanId}/publish");
        $response->assertStatus(200);
        $this->assertDatabaseHas('shift_plans', [
            'id' => $shiftPlanId,
            'status' => 'in_progress',
        ]);

        // ── 5. Create Fuel Entries ────────────────────────────────────
        // (a) Fuel for EXC-01
        $response = $this->postJson('/api/v1/admin/fuel-entries', [
            'shift_plan_id' => $shiftPlanId,
            'fuel_log_date' => $planningDate . ' 08:30:00',
            'equipment_allocation_id' => $allocExc1Id,
            'operator_id' => $this->operatorUsers[1]->id,
            'fuel_source' => 'fuel_station',
            'opening_fuel' => 200.00,
            'fuel_issued' => 150.00,
            'closing_fuel' => 50.00,
            'hours_meter_reading' => 100.00,
        ]);
        $response->assertStatus(201);
        $fuelExc1Id = $response->json('data.id');

        // (b) Fuel for DMP-01
        $response = $this->postJson('/api/v1/admin/fuel-entries', [
            'shift_plan_id' => $shiftPlanId,
            'fuel_log_date' => $planningDate . ' 09:00:00',
            'equipment_allocation_id' => $allocDmp1Id,
            'operator_id' => $this->driverUsers[1]->id,
            'fuel_source' => 'fuel_station',
            'opening_fuel' => 100.00,
            'fuel_issued' => 80.00,
            'closing_fuel' => 20.00,
            'kilometer_reading' => 500.00,
        ]);
        $response->assertStatus(201);
        $fuelDmp1Id = $response->json('data.id');

        // (c) Fuel for DMP-02
        $response = $this->postJson('/api/v1/admin/fuel-entries', [
            'shift_plan_id' => $shiftPlanId,
            'fuel_log_date' => $planningDate . ' 09:15:00',
            'equipment_allocation_id' => $allocDmp2Id,
            'operator_id' => $this->driverUsers[2]->id,
            'fuel_source' => 'fuel_station',
            'opening_fuel' => 120.00,
            'fuel_issued' => 60.00,
            'closing_fuel' => 30.00,
            'kilometer_reading' => 600.00,
        ]);
        $response->assertStatus(201);

        // (d) Fuel for DMP-03
        $response = $this->postJson('/api/v1/admin/fuel-entries', [
            'shift_plan_id' => $shiftPlanId,
            'fuel_log_date' => $planningDate . ' 09:30:00',
            'equipment_allocation_id' => $allocDmp3Id,
            'operator_id' => $this->driverUsers[3]->id,
            'fuel_source' => 'fuel_station',
            'opening_fuel' => 110.00,
            'fuel_issued' => 70.00,
            'closing_fuel' => 40.00,
            'kilometer_reading' => 550.00,
        ]);
        $response->assertStatus(201);

        // ── 6. Log Dispatch Trips (Simulating operational production) ──
        // Dumper 1: 2 trips, total quantity 220 BCM, cycle times 20 and 25 min (average 22.5 min)
        $this->postJson('/api/v1/dispatch/trips', [
            'shift_plan_id' => $shiftPlanId,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dmp1Name->id,
            'driver_id' => $this->drivers[1]->id,
            'excavator_equipment_id' => $this->exc1Name->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '08:10:00',
            'end_time' => '08:30:00',
            'quantity_bcm' => 100.00,
            'distance_meters' => 1000.00,
            'total_cycles' => 1,
        ])->assertStatus(201);

        $this->postJson('/api/v1/dispatch/trips', [
            'shift_plan_id' => $shiftPlanId,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dmp1Name->id,
            'driver_id' => $this->drivers[1]->id,
            'excavator_equipment_id' => $this->exc1Name->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '08:45:00',
            'end_time' => '09:10:00',
            'quantity_bcm' => 120.00,
            'distance_meters' => 1000.00,
            'total_cycles' => 1,
        ])->assertStatus(201);

        // Dumper 2: 2 trips, total quantity 170 BCM, cycle times 15 and 15 min (average 15.0 min)
        $this->postJson('/api/v1/dispatch/trips', [
            'shift_plan_id' => $shiftPlanId,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dmp2Name->id,
            'driver_id' => $this->drivers[2]->id,
            'excavator_equipment_id' => $this->exc1Name->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '08:10:00',
            'end_time' => '08:25:00',
            'quantity_bcm' => 80.00,
            'distance_meters' => 1000.00,
            'total_cycles' => 1,
        ])->assertStatus(201);

        $this->postJson('/api/v1/dispatch/trips', [
            'shift_plan_id' => $shiftPlanId,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dmp2Name->id,
            'driver_id' => $this->drivers[2]->id,
            'excavator_equipment_id' => $this->exc1Name->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '08:35:00',
            'end_time' => '08:50:00',
            'quantity_bcm' => 90.00,
            'distance_meters' => 1000.00,
            'total_cycles' => 1,
        ])->assertStatus(201);

        // Dumper 3: 1 trip, total quantity 95 BCM, cycle time 17 min (average 17.0 min)
        $this->postJson('/api/v1/dispatch/trips', [
            'shift_plan_id' => $shiftPlanId,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dmp3Name->id,
            'driver_id' => $this->drivers[3]->id,
            'excavator_equipment_id' => $this->exc2Name->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '08:15:00',
            'end_time' => '08:32:00',
            'quantity_bcm' => 95.00,
            'distance_meters' => 1000.00,
            'total_cycles' => 1,
        ])->assertStatus(201);

        // Verify shift plan's actual_bcm is updated
        // 220 + 170 + 95 = 485 BCM
        $this->assertDatabaseHas('shift_plans', [
            'id' => $shiftPlanId,
            'actual_bcm' => 485.00,
        ]);

        // Verify fuel entries received the dynamic BCM updates from trips
        // DMP-01 has 220 BCM work done
        $this->assertDatabaseHas('fuel_entries', [
            'id' => $fuelDmp1Id,
            'work_done_bcm' => 220.00,
            'fuel_per_bcm' => '0.7273',
        ]);

        // EXC-01 loaded DMP-01 (220 BCM) + DMP-02 (170 BCM) = 390 BCM
        $this->assertDatabaseHas('fuel_entries', [
            'id' => $fuelExc1Id,
            'work_done_bcm' => 390.00,
            'fuel_per_bcm' => '0.7692',
        ]);

        // ── 7. Breakdown Tickets and MTTR calculations ──────────────
        // Ticket 1: 120 minutes downtime (Closed)
        $response = $this->postJson('/api/v1/admin/maintenance/breakdowns', [
            'shift_id' => $this->shift->id,
            'equipment_id' => $this->dumperCat->id,
            'equipment_name_id' => $this->dmp1Name->id,
            'equipment_allocation_id' => $allocDmp1Id,
            'breakdown_date_time' => $planningDate . ' 09:30:00',
            'reported_by' => $this->supervisorEmp->id,
            'breakdown_type_id' => $this->breakdownType->id,
            'severity' => 'MEDIUM',
            'description' => 'Engine heating issue',
            'status' => 'open',
            'downtime_start' => $planningDate . ' 09:30:00',
        ]);
        $response->assertStatus(201);
        $ticket1Id = $response->json('data.id');

        // Close ticket 1
        $this->matchJson('PUT', "/api/v1/admin/maintenance/breakdowns/{$ticket1Id}", [
            'status' => 'closed',
            'downtime_end' => $planningDate . ' 11:30:00',
            'downtime_minutes' => 120,
            'resolution_notes' => 'Coolant refilled',
            'resolved_by' => $this->adminUser->id,
            'resolved_at' => $planningDate . ' 11:30:00',
        ])->assertStatus(200);

        // Ticket 2: 180 minutes downtime (Closed)
        $response = $this->postJson('/api/v1/admin/maintenance/breakdowns', [
            'shift_id' => $this->shift->id,
            'equipment_id' => $this->excavatorCat->id,
            'equipment_name_id' => $this->exc1Name->id,
            'equipment_allocation_id' => $allocExc1Id,
            'breakdown_date_time' => $planningDate . ' 12:00:00',
            'reported_by' => $this->supervisorEmp->id,
            'breakdown_type_id' => $this->breakdownType->id,
            'severity' => 'HIGH',
            'description' => 'Hydraulic seal failure',
            'status' => 'open',
            'downtime_start' => $planningDate . ' 12:00:00',
        ]);
        $ticket2Id = $response->json('data.id');

        // Close ticket 2
        $this->matchJson('PUT', "/api/v1/admin/maintenance/breakdowns/{$ticket2Id}", [
            'status' => 'closed',
            'downtime_end' => $planningDate . ' 15:00:00',
            'downtime_minutes' => 180,
            'resolution_notes' => 'Seal replaced',
            'resolved_by' => $this->adminUser->id,
            'resolved_at' => $planningDate . ' 15:00:00',
        ])->assertStatus(200);

        // ── 8. Create Operational Delay Linked to Breakdown ───────────
        $this->postJson('/api/v1/admin/delays', [
            'shift_plan_id' => $shiftPlanId,
            'shift_id' => $this->shift->id,
            'shift_date' => $planningDate,
            'shift_name' => 'Day Shift',
            'delay_log_date' => $planningDate . ' 09:30:00',
            'delay_category_id' => $this->delayCategory->id,
            'delay_subcategory' => 'Mechanical Repair',
            'start_time' => '09:30:00',
            'end_time' => '11:30:00',
            'duration_minutes' => 120,
            'severity' => 'MEDIUM',
            'linked_breakdown_id' => $ticket1Id,
            'equipment_id' => $this->dumperCat->id,
            'equipment_name_id' => $this->dmp1Name->id,
            'average_production_rate_per_hour' => 50.00,
            'estimated_production_loss_bcm' => 100.00,
            'description' => 'DMP-01 cooling system repair',
        ])->assertStatus(201);

        // ── 9. Asserts fleet performance KPIs ────────────────────────
        $response = $this->getJson("/api/v1/dispatch/fleet-performance?shift_plan_id={$shiftPlanId}");
        $response->assertStatus(200);

        // DMP-01 has 220 BCM (rank 1), DMP-02 has 170 BCM (rank 2), DMP-03 has 95 BCM (rank 3)
        $dumpers = $response->json('data.fleet_performance');
        $this->assertEquals($this->dmp1Name->id, $dumpers[0]['dumper_equipment_id']);
        $this->assertEquals(1, $dumpers[0]['fleet_rank']);
        $this->assertEquals($this->dmp2Name->id, $dumpers[1]['dumper_equipment_id']);
        $this->assertEquals(2, $dumpers[1]['fleet_rank']);
        $this->assertEquals($this->dmp3Name->id, $dumpers[2]['dumper_equipment_id']);
        $this->assertEquals(3, $dumpers[2]['fleet_rank']);
    }

    /**
     * Helper to perform PUT/PATCH using match method route since breakdowns controller supports it.
     */
    protected function matchJson($method, $uri, array $data = [], array $headers = [])
    {
        $files = $this->extractFilesFromDataArray($data);

        $content = json_encode($data);

        $headers = array_merge([
            'CONTENT_LENGTH' => mb_strlen($content, '8bit'),
            'CONTENT_TYPE' => 'application/json',
            'Accept' => 'application/json',
        ], $headers);

        return $this->call(
            $method,
            $uri,
            [],
            [],
            $files,
            $this->transformHeadersToServerVars($headers),
            $content
        );
    }
}
