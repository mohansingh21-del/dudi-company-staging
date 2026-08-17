<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use App\Models\Role;
use App\Models\User;
use App\Models\RoleUser;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Shift;
use App\Models\SitePoint;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\ShiftPlan;
use App\Models\ShiftEquipmentAllocation;
use App\Models\ShiftWorkforceDeployment;
use App\Models\DispatchTrip;
use App\Models\FuelEntry;
use App\Models\Delay;
use App\Models\DelayCategory;
use App\Models\BreakdownTicket;
use Carbon\Carbon;

class ShiftPlanSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $supervisorEmp;
    protected $inchargeEmp;
    protected $site;
    protected $shift;
    protected $loadingPoint;
    protected $dumpingPoint;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup Roles
        $superAdminRole = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);
        $supervisorRole = Role::create(['name' => 'Supervisor', 'slug' => 'supervisor', 'is_active' => 1]);
        $inchargeRole = Role::create(['name' => 'Site Incharge', 'slug' => 'site-incharge', 'is_active' => 1]);

        // Setup Users
        $this->adminUser = User::create(['email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->adminUser->roles()->attach($superAdminRole);

        $supervisorUser = User::create(['email' => 'supervisor@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->supervisorEmp = Employee::create([
            'employee_code' => 'EMP-SUP',
            'name' => 'Supervisor Employee',
            'joining_date' => '2026-01-01',
            'is_active' => true,
            'designation_id' => $supervisorRole->id,
            'role_user_id' => RoleUser::create(['user_id' => $supervisorUser->id, 'role_id' => $supervisorRole->id])->id,
        ]);

        $inchargeUser = User::create(['email' => 'incharge@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->inchargeEmp = Employee::create([
            'employee_code' => 'EMP-INC',
            'name' => 'Incharge Employee',
            'joining_date' => '2026-01-01',
            'is_active' => true,
            'designation_id' => $inchargeRole->id,
            'role_user_id' => RoleUser::create(['user_id' => $inchargeUser->id, 'role_id' => $inchargeRole->id])->id,
        ]);

        // Setup Site & Shift
        $this->site = Site::create(['site_name' => 'Test Mine Site', 'is_active' => true]);
        $this->shift = Shift::create([
            'shift_name' => 'Day Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'is_night_shift' => 0,
            'minimum_working_hours' => 8,
        ]);

        // Setup Loading & Dumping Points
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
    }

    public function test_unauthenticated_cannot_access_summary()
    {
        $response = $this->getJson('/api/v1/admin/shift-plans/1/summary');
        $response->assertStatus(401);
    }

    public function test_authenticated_retrieves_404_if_shift_plan_does_not_exist()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/admin/shift-plans/9999/summary');
        $response->assertStatus(404)
            ->assertJson([
                'status' => 404,
                'message' => 'Shift Plan Not Found.',
                'data' => []
            ]);
    }

    public function test_authenticated_retrieves_422_if_shift_plan_is_not_closed()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson('/api/v1/admin/shift-plans', [
            'planning_date' => '2026-07-02',
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1500.00,
            'supervisor_id' => $this->supervisorEmp->id,
            'site_incharge_id' => $this->inchargeEmp->id,
        ]);
        $response->assertStatus(201);
        $shiftPlanId = $response->json('data.id');

        $shiftPlan = ShiftPlan::find($shiftPlanId);
        $shiftPlan->update(['status' => 'published']);

        $response = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/summary");
        $response->assertStatus(422)
            ->assertJson([
                'status' => 422,
                'message' => 'Summary Is Only Available For Closed Shift Plans.',
                'data' => []
            ]);
    }

    public function test_authenticated_retrieves_200_with_correct_consolidated_summary_when_closed()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson('/api/v1/admin/shift-plans', [
            'planning_date' => '2026-07-02',
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1500.00,
            'supervisor_id' => $this->supervisorEmp->id,
            'site_incharge_id' => $this->inchargeEmp->id,
        ]);
        $response->assertStatus(201);
        $shiftPlanId = $response->json('data.id');

        $shiftPlan = ShiftPlan::find($shiftPlanId);
        $shiftPlan->update(['status' => 'completed']);

        // 1. Equipment Allocations
        $dumperCat = Equipment::create(['name' => 'Dumper', 'is_active' => true]);
        $excCat = Equipment::create(['name' => 'Excavator', 'is_active' => true]);
        $dumper1 = EquipmentName::create(['equipment_name' => 'DMP-01', 'equipment_id' => $dumperCat->id, 'is_active' => true]);
        $dumper2 = EquipmentName::create(['equipment_name' => 'DMP-02', 'equipment_id' => $dumperCat->id, 'is_active' => true]);
        $exc1 = EquipmentName::create(['equipment_name' => 'EXC-01', 'equipment_id' => $excCat->id, 'is_active' => true]);

        $alloc1 = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $dumper1->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now(),
        ]);

        $alloc2 = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $dumper2->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now(),
        ]);

        $alloc3 = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $exc1->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now(),
        ]);

        // 2. Workforce Deployment
        $driverEmp = Employee::create([
            'employee_code' => 'EMP-D1',
            'name' => 'Driver One',
            'joining_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $deployment = ShiftWorkforceDeployment::create([
            'shift_plan_id' => $shiftPlan->id,
            'employee_id' => $driverEmp->id,
            'relay_shift' => 'A',
            'home_relay_shift' => 'A',
            'assigned_machine_id' => $alloc1->id,
            'designation' => 'Driver',
            'is_borrowed' => false,
            'deployed_by' => $this->adminUser->id,
            'status' => 'active',
        ]);

        // 3. Dispatch Trips
        DispatchTrip::create([
            'trip_reference_no' => 'TRP-001',
            'shift_plan_id' => $shiftPlan->id,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $dumper1->id,
            'driver_id' => $driverEmp->id,
            'excavator_equipment_id' => $exc1->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'trip_date_time' => Carbon::parse('2026-07-02 09:00:00'),
            'start_time' => Carbon::parse('2026-07-02 09:00:00'),
            'end_time' => Carbon::parse('2026-07-02 09:10:00'),
            'cycle_time_minutes' => 10.00,
            'quantity_bcm' => 120.00,
            'total_cycles' => 1,
            'status' => 'completed',
            'created_by' => $this->adminUser->id,
        ]);

        DispatchTrip::create([
            'trip_reference_no' => 'TRP-002',
            'shift_plan_id' => $shiftPlan->id,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $dumper2->id,
            'driver_id' => $driverEmp->id,
            'excavator_equipment_id' => $exc1->id,
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'trip_date_time' => Carbon::parse('2026-07-02 09:15:00'),
            'start_time' => Carbon::parse('2026-07-02 09:15:00'),
            'end_time' => Carbon::parse('2026-07-02 09:30:00'),
            'cycle_time_minutes' => 15.00,
            'quantity_bcm' => 150.00,
            'total_cycles' => 1,
            'status' => 'completed',
            'created_by' => $this->adminUser->id,
        ]);

        // 4. Fuel Consumed
        FuelEntry::create([
            'fuel_ref_no' => 'FL-001',
            'shift_plan_id' => $shiftPlan->id,
            'fuel_log_date' => Carbon::parse('2026-07-02 08:30:00'),
            'shift_id' => $this->shift->id,
            'equipment_allocation_id' => $alloc1->id,
            'equipment_id' => $dumperCat->id,
            'equipment_name_id' => $dumper1->id,
            'opening_fuel' => 100.00,
            'fuel_issued' => 50.00,
            'closing_fuel' => 110.00,
            'fuel_consumption' => 40.00,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
        ]);

        // 5. Delays
        $delayCat = DelayCategory::create([
            'delay_category' => 'Mechanical Break',
            'description' => 'Mechanical delay category',
            'is_active' => true,
        ]);

        Delay::create([
            'delay_ref_no' => 'DLY-001',
            'shift_plan_id' => $shiftPlan->id,
            'shift_id' => $this->shift->id,
            'shift_date' => '2026-07-02',
            'shift_name' => 'Day Shift',
            'delay_log_date' => Carbon::parse('2026-07-02 10:00:00'),
            'delay_category_id' => $delayCat->id,
            'delay_subcategory' => 'Engine Overheat',
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'duration_minutes' => 30,
            'severity' => 'LOW',
            'description' => 'Engine overheat issue',
            'created_by' => $this->adminUser->id,
        ]);

        // 6. Breakdown Ticket
        BreakdownTicket::create([
            'ticket_number' => 'TCK-001',
            'shift_id' => $this->shift->id,
            'equipment_id' => $dumperCat->id,
            'equipment_name_id' => $dumper1->id,
            'equipment_allocation_id' => $alloc1->id,
            'breakdown_date_time' => Carbon::parse('2026-07-02 11:00:00'),
            'reported_by' => $driverEmp->id,
            'breakdown_type_id' => 1,
            'severity' => 'MEDIUM',
            'description' => 'Breakdown description',
            'status' => 'closed',
            'downtime_start' => Carbon::parse('2026-07-02 11:00:00'),
            'downtime_end' => Carbon::parse('2026-07-02 11:45:00'),
            'downtime_minutes' => 45,
            'resolved_by' => $this->adminUser->id,
            'resolved_at' => Carbon::parse('2026-07-02 11:45:00'),
        ]);

        $response = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/summary");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'shift_plan' => [
                        'id',
                        'shift_reference_number',
                        'planning_date',
                        'shift_id',
                        'location_id',
                        'location_name',
                        'target_bcm',
                        'supervisor_name',
                        'site_incharge_name',
                        'status',
                        'shift' => [
                            'id',
                            'shift_name',
                            'start_time',
                            'end_time',
                            'minimum_working_hours',
                            'is_night_shift',
                        ]
                    ],
                    'production_summary' => [
                        'actual_bcm',
                        'achievement_percent',
                        'variance_bcm',
                    ],
                    'equipment_summary' => [
                        'total_equipment_allocated',
                        'equipment_utilized',
                        'equipment_availability_percent',
                        'total_downtime_hours',
                        'top_performing_equipment',
                        'excavator_performance',
                        'dumper_performance',
                    ],
                    'workforce_summary' => [
                        'total_employees_deployed',
                        'borrowed_employees_count',
                    ],
                    'fuel_summary' => [
                        'total_fuel_consumed',
                    ],
                    'delay_summary' => [
                        'total_delay_minutes',
                        'total_delay_hours',
                        'delay_count',
                        'top_delay_reason',
                    ],
                    'breakdown_summary' => [
                        'breakdown_count',
                        'total_downtime_minutes',
                        'total_downtime_hours',
                    ],
                    'safety_summary' => [
                        'incident_count',
                        'status',
                    ],
                    'fleet_performance' => [
                        'active_dumpers_count',
                        'haul_cycle_avg_minutes',
                        'payload_avg_bcm',
                    ]
                ]
            ]);

        $data = $response->json('data');

        // Check base shift plan info
        $this->assertEquals($shiftPlan->id, $data['shift_plan']['id']);
        $this->assertEquals('2026-07-02', $data['shift_plan']['planning_date']);
        $this->assertEquals('Test Mine Site', $data['shift_plan']['location_name']);
        $this->assertEquals(1500.00, $data['shift_plan']['target_bcm']);
        $this->assertEquals('Supervisor Employee', $data['shift_plan']['supervisor_name']);
        $this->assertEquals('Incharge Employee', $data['shift_plan']['site_incharge_name']);
        $this->assertEquals('completed', $data['shift_plan']['status']);

        // Check nested shift details
        $this->assertEquals($this->shift->id, $data['shift_plan']['shift']['id']);
        $this->assertEquals($this->shift->shift_name, $data['shift_plan']['shift']['shift_name']);
        $this->assertEquals($this->shift->start_time, $data['shift_plan']['shift']['start_time']);
        $this->assertEquals($this->shift->end_time, $data['shift_plan']['shift']['end_time']);
        $this->assertEquals($this->shift->minimum_working_hours, $data['shift_plan']['shift']['minimum_working_hours']);
        $this->assertEquals($this->shift->is_night_shift, $data['shift_plan']['shift']['is_night_shift']);

        // Check production summary
        // actual_bcm = 120 + 150 = 270
        // achievement_percent = (270 / 1500) * 100 = 18.00
        // variance_bcm = 270 - 1500 = -1230.00
        $this->assertEquals(270.00, $data['production_summary']['actual_bcm']);
        $this->assertEquals(18.00, $data['production_summary']['achievement_percent']);
        $this->assertEquals(-1230.00, $data['production_summary']['variance_bcm']);

        // Check equipment summary
        // total_allocated = 3 (dumper1, dumper2, exc1)
        // equipment_utilized = 3 (dumper1, dumper2, exc1 all had dispatch trips)
        // availability_percent = ((3 * 8 - 0.75) / 24) * 100 = 96.88%
        // total_downtime_hours = 45 / 60 = 0.75
        $this->assertEquals(3, $data['equipment_summary']['total_equipment_allocated']);
        $this->assertEquals(3, $data['equipment_summary']['equipment_utilized']);
        $this->assertEquals(96.88, $data['equipment_summary']['equipment_availability_percent']);
        $this->assertEquals(0.75, $data['equipment_summary']['total_downtime_hours']);
        $this->assertCount(2, $data['equipment_summary']['top_performing_equipment']);
        $this->assertCount(1, $data['equipment_summary']['excavator_performance']);
        $this->assertCount(2, $data['equipment_summary']['dumper_performance']);

        // Check workforce summary
        $this->assertEquals(1, $data['workforce_summary']['total_employees_deployed']);
        $this->assertEquals(0, $data['workforce_summary']['borrowed_employees_count']);

        // Check fuel summary
        $this->assertEquals(40.00, $data['fuel_summary']['total_fuel_consumed']);

        // Check delay summary
        $this->assertEquals(30, $data['delay_summary']['total_delay_minutes']);
        $this->assertEquals(0.5, $data['delay_summary']['total_delay_hours']);
        $this->assertEquals(1, $data['delay_summary']['delay_count']);
        $this->assertEquals('Mechanical Break', $data['delay_summary']['top_delay_reason']);

        // Check breakdown summary
        $this->assertEquals(1, $data['breakdown_summary']['breakdown_count']);
        $this->assertEquals(45, $data['breakdown_summary']['total_downtime_minutes']);
        $this->assertEquals(0.75, $data['breakdown_summary']['total_downtime_hours']);

        // Check safety summary
        $this->assertEquals(0, $data['safety_summary']['incident_count']);
        $this->assertEquals('SAFE', $data['safety_summary']['status']);

        // Check fleet performance
        $this->assertEquals(2, $data['fleet_performance']['active_dumpers_count']);
        $this->assertEquals(12.5, $data['fleet_performance']['haul_cycle_avg_minutes']);
        $this->assertEquals(135.0, $data['fleet_performance']['payload_avg_bcm']);
    }

    public function test_summary_actual_bcm_fallback_when_excavator_id_null()
    {
        Sanctum::actingAs($this->adminUser);

        // 1. Create a shift plan
        $response = $this->postJson('/api/v1/admin/shift-plans', [
            'planning_date' => '2026-07-03',
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1000.00,
            'supervisor_id' => $this->supervisorEmp->id,
            'site_incharge_id' => $this->inchargeEmp->id,
        ]);
        $response->assertStatus(201);
        $shiftPlanId = $response->json('data.id');

        $shiftPlan = ShiftPlan::find($shiftPlanId);

        // 2. Equipment Allocations
        $dumperCat = Equipment::create(['name' => 'Dumper', 'is_active' => true]);
        $excCat = Equipment::create(['name' => 'Excavator', 'is_active' => true]);
        $dumper = EquipmentName::create(['equipment_name' => 'DMP-99', 'equipment_id' => $dumperCat->id, 'is_active' => true]);
        $exc = EquipmentName::create(['equipment_name' => 'EXC-99', 'equipment_id' => $excCat->id, 'is_active' => true]);

        $allocExc = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $exc->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now(),
        ]);

        $allocDumper = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $dumper->id,
            'parent_equipment_id' => $exc->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now(),
        ]);

        // 3. Create dispatch trip with excavator_equipment_id = null
        DispatchTrip::create([
            'trip_reference_no' => 'TRP-999',
            'shift_plan_id' => $shiftPlan->id,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'dumper_equipment_id' => $dumper->id,
            'driver_id' => $this->supervisorEmp->id,
            'excavator_equipment_id' => null, // Explicitly null
            'loading_point_id' => $this->loadingPoint->id,
            'dumping_point_id' => $this->dumpingPoint->id,
            'trip_date_time' => Carbon::parse('2026-07-03 09:00:00'),
            'start_time' => Carbon::parse('2026-07-03 09:00:00'),
            'end_time' => Carbon::parse('2026-07-03 09:10:00'),
            'cycle_time_minutes' => 10.00,
            'quantity_bcm' => 250.00,
            'total_cycles' => 1,
            'status' => 'completed',
            'created_by' => $this->adminUser->id,
        ]);

        // 4. Mark shift plan completed
        $shiftPlan->update(['status' => 'completed']);

        // 5. Get summary
        $response = $this->getJson("/api/v1/admin/shift-plans/{$shiftPlan->id}/summary");
        $response->assertStatus(200);

        $data = $response->json('data');
        $excPerformance = $data['equipment_summary']['excavator_performance'];

        // Assert there is one excavator performance record and its actual_bcm is 250.00
        $this->assertCount(1, $excPerformance);
        $this->assertEquals('EXC-99', $excPerformance[0]['asset_id']);
        $this->assertEquals(250.00, $excPerformance[0]['actual_bcm']);
        $this->assertEquals(25.00, $excPerformance[0]['efficiency']); // 250 / 1000 = 25%
    }
}
