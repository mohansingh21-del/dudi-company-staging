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
use App\Models\SitePoint;
use App\Models\ShiftEquipmentAllocation;
use App\Models\DispatchTrip;
use App\Models\DispatchTripAudit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DispatchTripManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $supervisorUser;
    protected $supervisorEmployee;
    protected $otherUser;

    protected $shift;
    protected $site;
    protected $dumperCategory;
    protected $excavatorCategory;
    protected $dumperName;
    protected $excavatorName;
    protected $unallocatedDumperName;
    
    protected $loadingPoint;
    protected $dumpingPoint;

    protected $publishedShiftPlan;
    protected $completedShiftPlan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-02 12:00:00'));

        // Create Roles
        $adminRole = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);
        $supervisorRole = Role::create(['name' => 'Supervisor', 'slug' => 'supervisor', 'is_active' => 1]);
        $otherRole = Role::create(['name' => 'Other', 'slug' => 'other', 'is_active' => 1]);

        // Create Users
        $this->adminUser = User::create(['email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->adminUser->roles()->attach($adminRole);

        $this->supervisorUser = User::create(['email' => 'supervisor@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->supervisorUser->roles()->attach($supervisorRole);

        $this->otherUser = User::create(['email' => 'other@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->otherUser->roles()->attach($otherRole);

        // Link Supervisor User to Employee
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

        // Create Equipment (Categories)
        $this->dumperCategory = Equipment::create([
            'name'      => 'Dumper Truck',
            'is_active' => 1,
        ]);
        $this->excavatorCategory = Equipment::create([
            'name'      => 'Excavator',
            'is_active' => 1,
        ]);

        // Create EquipmentName (Machine Instances)
        $this->dumperName = EquipmentName::create([
            'equipment_id'   => $this->dumperCategory->id,
            'equipment_name' => 'DMP-01',
            'is_active'      => 1,
        ]);
        $this->unallocatedDumperName = EquipmentName::create([
            'equipment_id'   => $this->dumperCategory->id,
            'equipment_name' => 'DMP-99',
            'is_active'      => 1,
        ]);
        $this->excavatorName = EquipmentName::create([
            'equipment_id'   => $this->excavatorCategory->id,
            'equipment_name' => 'EXC-01',
            'is_active'      => 1,
        ]);

        // Create SitePoints
        $this->loadingPoint = SitePoint::create([
            'site_id' => $this->site->id,
            'name' => 'Loading Pit A',
            'type' => 'loading',
            'latitude' => '23.456',
            'longitude' => '85.678',
            'radius_meters' => 50,
            'is_active' => 1,
        ]);
        $this->dumpingPoint = SitePoint::create([
            'site_id' => $this->site->id,
            'name' => 'Waste Dump B',
            'type' => 'dumping',
            'latitude' => '23.490',
            'longitude' => '85.690',
            'radius_meters' => 50,
            'is_active' => 1,
        ]);

        // Create Published Shift Plan
        $this->publishedShiftPlan = ShiftPlan::create([
            'planning_date' => '2026-07-02',
            'shift_id'      => $this->shift->id,
            'site_id'       => $this->site->id,
            'target_bcm'    => 1000,
            'status'        => 'published',
            'supervisor_id' => $this->supervisorUser->id,
            'site_incharge_id' => $this->supervisorUser->id,
            'created_by'    => $this->adminUser->id,
            'reference_no'  => 'SP-PUB-001',
        ]);

        // Create Completed Shift Plan
        $this->completedShiftPlan = ShiftPlan::create([
            'planning_date' => '2026-07-01',
            'shift_id'      => $this->shift->id,
            'site_id'       => $this->site->id,
            'target_bcm'    => 1000,
            'status'        => 'closed',
            'supervisor_id' => $this->supervisorUser->id,
            'site_incharge_id' => $this->supervisorUser->id,
            'created_by'    => $this->adminUser->id,
            'reference_no'  => 'SP-CMP-001',
        ]);

        // Allocate Equipment in Shift
        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'equipment_name_id' => $this->dumperName->id,
            'parent_equipment_id' => $this->excavatorName->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now()->toDateTimeString(),
        ]);
        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'equipment_name_id' => $this->excavatorName->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now()->toDateTimeString(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_supervisor_can_log_trip_successfully()
    {
        Sanctum::actingAs($this->supervisorUser);

        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '09:00:00',
            'end_time' => '09:20:00', // 20 minutes
            'quantity_bcm' => 15.5,
            'distance_meters' => 1200,
            'total_cycles' => 1,
        ];

        $response = $this->postJson('/api/v1/dispatch/trips', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 201)
            ->assertJsonPath('message', 'Trip Logged Successfully.')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'id',
                    'trip_reference_no',
                    'cycle_time_minutes',
                ]
            ]);

        $this->assertEquals(20.0, (float) $response->json('data.cycle_time_minutes'));

        // Check DB entry
        $this->assertDatabaseHas('dispatch_trips', [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'trip_date_time' => '2026-07-02 09:00:00',
            'dumper_equipment_id' => $this->dumperName->id,
            'cycle_time_minutes' => 20.0,
            'quantity_bcm' => 15.5,
            'total_cycles' => 1,
        ]);

        // Check that Shift Plan's actual_bcm was updated
        $this->assertDatabaseHas('shift_plans', [
            'id' => $this->publishedShiftPlan->id,
            'actual_bcm' => 15.5,
        ]);
    }

    public function test_validation_fails_if_dumper_not_allocated_to_shift()
    {
        Sanctum::actingAs($this->supervisorUser);

        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->unallocatedDumperName->id, // not allocated
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '09:00:00',
            'end_time' => '09:20:00',
            'quantity_bcm' => 15.5,
        ];

        $response = $this->postJson('/api/v1/dispatch/trips', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dumper_equipment_id']);
    }

    public function test_other_roles_cannot_log_trips()
    {
        Sanctum::actingAs($this->otherUser);

        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '09:00:00',
            'end_time' => '09:20:00',
            'quantity_bcm' => 15.5,
        ];

        $response = $this->postJson('/api/v1/dispatch/trips', $payload);
        $response->assertStatus(403);
    }

    public function test_can_view_dashboard()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Seed a trip
        DispatchTrip::create([
            'trip_reference_no' => 'TRP-2026-000001',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'trip_date_time' => '2026-07-02 09:00:00',
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '2026-07-02 09:00:00',
            'end_time' => '2026-07-02 09:10:00',
            'cycle_time_minutes' => 10,
            'quantity_bcm' => 20,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->getJson('/api/v1/dispatch/dashboard?date_from=2026-07-02 00:00:00&date_to=2026-07-02 23:59:59');

        $response->assertStatus(200)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.kpi_cards.total_trips', 1)
            ->assertJsonPath('data.kpi_cards.total_quantity_bcm', 20)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'kpi_cards' => [
                        'total_trips',
                        'total_quantity_bcm',
                        'average_cycle_time_minutes',
                        'active_dumpers',
                    ],
                    'fleet_productivity_trend',
                    'top_performing_dumpers',
                    'recent_trip_activity',
                ]
            ]);
    }

    public function test_can_view_trips_index_with_filters()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Seed Trip 1: Day shift on 2026-07-02
        DispatchTrip::create([
            'trip_reference_no' => 'TRP-2026-000002',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'trip_date_time' => '2026-07-02 09:00:00',
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '2026-07-02 09:00:00',
            'end_time' => '2026-07-02 09:10:00',
            'cycle_time_minutes' => 10,
            'quantity_bcm' => 20,
            'created_by' => $this->adminUser->id,
        ]);

        // Create a different shift
        $otherShift = Shift::create([
            'shift_name'            => 'Night Shift Test',
            'start_time'            => '20:00:00',
            'end_time'              => '04:00:00',
            'minimum_working_hours' => 8,
            'is_night_shift'        => 1,
        ]);

        // Seed Trip 2: Night shift on 2026-07-03
        DispatchTrip::create([
            'trip_reference_no' => 'TRP-2026-000099',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $otherShift->id,
            'trip_date_time' => '2026-07-03 21:00:00',
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '2026-07-03 21:00:00',
            'end_time' => '2026-07-03 21:10:00',
            'cycle_time_minutes' => 10,
            'quantity_bcm' => 15,
            'created_by' => $this->adminUser->id,
        ]);

        // 1. Filter by shift_plan_id
        $response = $this->getJson('/api/v1/dispatch/trips?shift_plan_id=' . $this->publishedShiftPlan->id);
        $response->assertStatus(200)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('dashboard.total_trips', 2);

        // 2. Filter by shift_id (should only return Trip 1)
        $response = $this->getJson('/api/v1/dispatch/trips?shift_id=' . $this->shift->id);
        $response->assertStatus(200)
            ->assertJsonPath('dashboard.total_trips', 1)
            ->assertJsonPath('data.0.trip_reference_no', 'TRP-2026-000002');

        // 3. Filter by otherShift shift_id (should only return Trip 2)
        $response = $this->getJson('/api/v1/dispatch/trips?shift_id=' . $otherShift->id);
        $response->assertStatus(200)
            ->assertJsonPath('dashboard.total_trips', 1)
            ->assertJsonPath('data.0.trip_reference_no', 'TRP-2026-000099');

        // 4. Filter by date range that only covers 2026-07-02
        $response = $this->getJson('/api/v1/dispatch/trips?date_from=2026-07-02 00:00:00&date_to=2026-07-02 23:59:59');
        $response->assertStatus(200)
            ->assertJsonPath('dashboard.total_trips', 1)
            ->assertJsonPath('data.0.trip_reference_no', 'TRP-2026-000002');

        // 5. Filter by date range that only covers 2026-07-03
        $response = $this->getJson('/api/v1/dispatch/trips?date_from=2026-07-03 00:00:00&date_to=2026-07-03 23:59:59');
        $response->assertStatus(200)
            ->assertJsonPath('dashboard.total_trips', 1)
            ->assertJsonPath('data.0.trip_reference_no', 'TRP-2026-000099');
    }

    public function test_updating_trip_recalculates_cycle_time_and_records_audit_trail()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Seed trip
        $trip = DispatchTrip::create([
            'trip_reference_no' => 'TRP-2026-000003',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'trip_date_time' => '2026-07-02 09:00:00',
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '2026-07-02 09:00:00',
            'end_time' => '2026-07-02 09:10:00',
            'cycle_time_minutes' => 10,
            'quantity_bcm' => 20,
            'created_by' => $this->adminUser->id,
        ]);

        $payload = [
            'start_time' => '09:00:00',
            'end_time' => '09:30:00', // updated to 30 mins
            'quantity_bcm' => 25.5,
        ];

        $response = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 200);

        $this->assertEquals(30.0, (float) $response->json('data.cycle_time_minutes'));

        // Check audit log
        $this->assertDatabaseHas('dispatch_trip_audits', [
            'dispatch_trip_id' => $trip->id,
            'changed_by' => $this->supervisorUser->id,
        ]);

        $audit = DispatchTripAudit::where('dispatch_trip_id', $trip->id)->first();
        $this->assertEquals(20.00, $audit->changed_fields['quantity_bcm'][0]);
        $this->assertEquals(25.50, $audit->changed_fields['quantity_bcm'][1]);
    }

    public function test_cannot_update_immutable_fields()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Seed trip
        $trip = DispatchTrip::create([
            'trip_reference_no' => 'TRP-2026-000004',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'trip_date_time' => '2026-07-02 09:00:00',
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '2026-07-02 09:00:00',
            'end_time' => '2026-07-02 09:10:00',
            'cycle_time_minutes' => 10,
            'quantity_bcm' => 20,
            'created_by' => $this->adminUser->id,
        ]);

        // 1. Test mutating trip_reference_no
        $payload1 = [
            'trip_reference_no' => 'TRP-MUTATED-999',
        ];
        $response1 = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", $payload1);
        $response1->assertStatus(422)
            ->assertJsonValidationErrors(['trip_reference_no']);

        // 2. Test mutating dumper_equipment_id (different but valid ID)
        $payload2 = [
            'dumper_equipment_id' => $this->unallocatedDumperName->id,
        ];
        $response2 = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", $payload2);
        $response2->assertStatus(422)
            ->assertJsonValidationErrors(['dumper_equipment_id']);

        // 3. Test mutating dumper_equipment_id (invalid/non-existent ID)
        $payload3 = [
            'dumper_equipment_id' => 99999,
        ];
        $response3 = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", $payload3);
        $response3->assertStatus(422)
            ->assertJsonValidationErrors(['dumper_equipment_id']);

        // 4. Test mutating site_id (different but valid ID, fails shift plan lookup)
        $anotherSite = Site::create(['site_name' => 'Another Site', 'is_active' => 1]);
        $payload4 = [
            'site_id' => $anotherSite->id,
        ];
        $response4 = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", $payload4);
        $response4->assertStatus(422)
            ->assertJsonValidationErrors(['shift_plan_id']);

        // 5. Test mutating site_id (invalid/non-existent ID)
        $payload5 = [
            'site_id' => 99999,
        ];
        $response5 = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", $payload5);
        $response5->assertStatus(422)
            ->assertJsonValidationErrors(['site_id']);
    }

    public function test_modifying_completed_shift_trip_is_blocked_for_normal_user_but_allowed_with_override()
    {
        // 1. Log trip under completed shift
        $trip = DispatchTrip::create([
            'trip_reference_no' => 'TRP-2026-000005',
            'shift_plan_id' => $this->completedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'trip_date_time' => '2026-07-01 09:00:00',
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '2026-07-01 09:00:00',
            'end_time' => '2026-07-01 09:10:00',
            'cycle_time_minutes' => 10,
            'quantity_bcm' => 20,
            'created_by' => $this->adminUser->id,
        ]);

        // 2. Normal Supervisor tries to update -> Blocked 403
        Sanctum::actingAs($this->supervisorUser);

        $payload = [
            'quantity_bcm' => 25.0,
        ];

        $response = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", $payload);
        $response->assertStatus(403)
            ->assertJsonPath('message', 'You do not have permission to modify data for a completed shift.');

        // 3. Super Admin tries to update -> Allowed (via mock/gate or admin role)
        // Since Gate allows it by default for admins or we can define the gate. Let's register the gate in our AuthServiceProvider to make sure it functions!
        Sanctum::actingAs($this->adminUser);
        
        $response = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", $payload);
        $response->assertStatus(200);
    }

    public function test_supervisor_can_log_trip_crossing_midnight_in_night_shift()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Create Night Shift
        $nightShift = Shift::create([
            'shift_name'            => 'Night Shift',
            'start_time'            => '20:00:00',
            'end_time'              => '04:00:00',
            'minimum_working_hours' => 8,
            'is_night_shift'        => 1,
        ]);

        // Create Published Shift Plan for Night Shift
        $nightShiftPlan = ShiftPlan::create([
            'planning_date' => '2026-07-02',
            'shift_id'      => $nightShift->id,
            'site_id'       => $this->site->id,
            'target_bcm'    => 1000,
            'status'        => 'published',
            'supervisor_id' => $this->supervisorUser->id,
            'site_incharge_id' => $this->supervisorUser->id,
            'created_by'    => $this->adminUser->id,
            'reference_no'  => 'SP-PUB-002',
        ]);

        // Allocate Equipment in Shift
        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $nightShiftPlan->id,
            'equipment_name_id' => $this->dumperName->id,
            'parent_equipment_id' => $this->excavatorName->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now()->toDateTimeString(),
        ]);
        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $nightShiftPlan->id,
            'equipment_name_id' => $this->excavatorName->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now()->toDateTimeString(),
        ]);

        // Log trip starting at 23:50:00 (day 1) and ending at 00:15:00 (day 2)
        $payload = [
            'shift_plan_id' => $nightShiftPlan->id,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '23:50:00',
            'end_time' => '00:15:00', // 25 minutes
            'quantity_bcm' => 12.0,
            'distance_meters' => 800,
            'total_cycles' => 1,
        ];

        $response = $this->postJson('/api/v1/dispatch/trips', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 201)
            ->assertJsonPath('message', 'Trip Logged Successfully.');

        $this->assertEquals(25.0, (float) $response->json('data.cycle_time_minutes'));

        // Check DB entry. Because start_time is 23:50:00, it's >= 20:00:00, so it remains on 2026-07-02.
        // end_time is 00:15:00, which is < 20:00:00, so it shifts to 2026-07-03.
        $this->assertDatabaseHas('dispatch_trips', [
            'shift_plan_id' => $nightShiftPlan->id,
            'shift_id' => $nightShift->id,
            'start_time' => '2026-07-02 23:50:00',
            'end_time' => '2026-07-03 00:15:00',
            'trip_date_time' => '2026-07-02 23:50:00',
            'cycle_time_minutes' => 25.0,
        ]);
    }

    public function test_mapping_validation_rules()
    {
        Sanctum::actingAs($this->supervisorUser);

        // Create another site point and excavator for testing wrong mappings
        $otherSite = Site::create(['site_name' => 'Other Site', 'is_active' => 1]);
        $wrongLoadingPoint = SitePoint::create([
            'site_id' => $otherSite->id,
            'name' => 'Loading Point Site 2',
            'type' => 'loading',
            'latitude' => '23.456',
            'longitude' => '85.678',
            'radius_meters' => 50,
            'is_active' => 1,
        ]);
        $wrongDumpingPoint = SitePoint::create([
            'site_id' => $otherSite->id,
            'name' => 'Dumping Point Site 2',
            'type' => 'dumping',
            'latitude' => '23.490',
            'longitude' => '85.690',
            'radius_meters' => 50,
            'is_active' => 1,
        ]);

        $wrongExcavator = EquipmentName::create([
            'equipment_id'   => $this->excavatorCategory->id,
            'equipment_name' => 'EXC-99',
            'is_active'      => 1,
        ]);
        // Allocate wrong excavator to the same shift plan so it is assigned to the shift,
        // but is NOT mapped to our dumper Name.
        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'equipment_name_id' => $wrongExcavator->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now()->toDateTimeString(),
        ]);

        // 1. Test Store Trip: Wrong Excavator (not mapped to the dumper)
        $payloadStoreWrongExcavator = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $wrongExcavator->id, // not parent of dumper
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '09:00:00',
            'end_time' => '09:10:00',
            'quantity_bcm' => 20.0,
        ];
        $response = $this->postJson('/api/v1/dispatch/trips', $payloadStoreWrongExcavator);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['excavator_equipment_id']);

        // 2. Test Store Trip: Loading point from wrong site
        $payloadStoreWrongLoading = $payloadStoreWrongExcavator;
        $payloadStoreWrongLoading['excavator_equipment_id'] = $this->excavatorName->id;
        $payloadStoreWrongLoading['loading_point_id'] = $wrongLoadingPoint->id;
        $response = $this->postJson('/api/v1/dispatch/trips', $payloadStoreWrongLoading);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['loading_point_id']);

        // 3. Test Store Trip: Dumping point from wrong site
        $payloadStoreWrongDumping = $payloadStoreWrongExcavator;
        $payloadStoreWrongDumping['excavator_equipment_id'] = $this->excavatorName->id;
        $payloadStoreWrongDumping['dumping_point_id'] = $wrongDumpingPoint->id;
        $response = $this->postJson('/api/v1/dispatch/trips', $payloadStoreWrongDumping);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dumping_point_id']);

        // Seed a valid trip to test updates
        $trip = DispatchTrip::create([
            'trip_reference_no' => 'TRP-2026-000007',
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'shift_id' => $this->shift->id,
            'trip_date_time' => '2026-07-02 09:00:00',
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '2026-07-02 09:00:00',
            'end_time' => '2026-07-02 09:10:00',
            'cycle_time_minutes' => 10,
            'quantity_bcm' => 20,
            'created_by' => $this->adminUser->id,
        ]);

        // 4. Test Update Trip: Wrong Excavator
        $response = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", [
            'excavator_equipment_id' => $wrongExcavator->id,
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['excavator_equipment_id']);

        // 5. Test Update Trip: Wrong Loading Point
        $response = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", [
            'loading_point_id' => $wrongLoadingPoint->id,
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['loading_point_id']);

        // 6. Test Update Trip: Wrong Dumping Point
        $response = $this->putJson("/api/v1/dispatch/trips/{$trip->id}", [
            'dumping_point_id' => $wrongDumpingPoint->id,
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['dumping_point_id']);
    }

    public function test_user_can_insert_and_update_site_id_and_shift_id()
    {
        Sanctum::actingAs($this->supervisorUser);

        // 1. Create a different site, shift, and a published shift plan for that combination
        $newSite = Site::create(['site_name' => 'North Quarry', 'is_active' => 1]);
        $newShift = Shift::create([
            'shift_name'            => 'Evening Shift',
            'start_time'            => '16:00:00',
            'end_time'              => '20:00:00',
            'minimum_working_hours' => 4,
            'is_night_shift'        => 0,
        ]);
        
        $newShiftPlan = ShiftPlan::create([
            'planning_date' => '2026-07-02',
            'shift_id'      => $newShift->id,
            'site_id'       => $newSite->id,
            'target_bcm'    => 500,
            'status'        => 'published',
            'supervisor_id' => $this->supervisorUser->id,
            'site_incharge_id' => $this->supervisorUser->id,
            'created_by'    => $this->adminUser->id,
            'reference_no'  => 'SP-PUB-003',
        ]);

        // Allocate dumper and excavator to the new shift plan as well
        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $newShiftPlan->id,
            'equipment_name_id' => $this->dumperName->id,
            'parent_equipment_id' => $this->excavatorName->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now()->toDateTimeString(),
        ]);
        ShiftEquipmentAllocation::create([
            'shift_plan_id' => $newShiftPlan->id,
            'equipment_name_id' => $this->excavatorName->id,
            'parent_equipment_id' => null,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now()->toDateTimeString(),
        ]);

        // Create new loading and dumping points for the new site
        $newLoadingPoint = SitePoint::create([
            'site_id' => $newSite->id,
            'name' => 'Loading Point North',
            'type' => 'loading',
            'latitude' => '24.456',
            'longitude' => '86.678',
            'radius_meters' => 50,
            'is_active' => 1,
        ]);
        $newDumpingPoint = SitePoint::create([
            'site_id' => $newSite->id,
            'name' => 'Dumping Point North',
            'type' => 'dumping',
            'latitude' => '24.490',
            'longitude' => '86.690',
            'radius_meters' => 50,
            'is_active' => 1,
        ]);

        // 2. Test Store Trip: using site_id and shift_id (without shift_plan_id)
        $payloadStore = [
            'site_id' => $newSite->id,
            'shift_id' => $newShift->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $newLoadingPoint->id,
            'dumping_point_id' => $newDumpingPoint->id,
            'start_time' => '17:00:00',
            'end_time' => '17:15:00',
            'quantity_bcm' => 15.0,
            'total_cycles' => 1,
        ];
        $response = $this->postJson('/api/v1/dispatch/trips', $payloadStore);
        $response->assertStatus(201);
        $this->assertEquals($newShiftPlan->id, $response->json('data.shift_plan_id'));

        $tripId = $response->json('data.id');

        // 3. Test Update Trip: move it back to the original shift and site (publishedShiftPlan)
        $payloadUpdate = [
            'site_id' => $this->site->id,
            'shift_id' => $this->shift->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
        ];
        $responseUpdate = $this->putJson("/api/v1/dispatch/trips/{$tripId}", $payloadUpdate);
        $responseUpdate->assertStatus(200);

        $this->assertDatabaseHas('dispatch_trips', [
            'id' => $tripId,
            'site_id' => $this->site->id,
            'shift_id' => $this->shift->id,
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
        ]);
    }

    public function test_supervisor_can_log_and_update_total_cycles()
    {
        Sanctum::actingAs($this->supervisorUser);

        $payload = [
            'shift_plan_id' => $this->publishedShiftPlan->id,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $this->dumperName->id,
            'driver_id' => $this->supervisorEmployee->id,
            'excavator_equipment_id' => $this->excavatorName->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'start_time' => '09:00:00',
            'end_time' => '09:20:00',
            'quantity_bcm' => 15.5,
            'distance_meters' => 1200,
            'total_cycles' => 5,
        ];

        $response = $this->postJson('/api/v1/dispatch/trips', $payload);

        $response->assertStatus(201);
        $tripId = $response->json('data.id');

        $this->assertDatabaseHas('dispatch_trips', [
            'id' => $tripId,
            'total_cycles' => 5,
        ]);

        $payloadUpdate = [
            'total_cycles' => 8,
        ];
        $responseUpdate = $this->putJson("/api/v1/dispatch/trips/{$tripId}", $payloadUpdate);
        $responseUpdate->assertStatus(200);

        $this->assertDatabaseHas('dispatch_trips', [
            'id' => $tripId,
            'total_cycles' => 8,
        ]);
    }
}
