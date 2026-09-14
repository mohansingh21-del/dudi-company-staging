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
use App\Models\BreakdownType;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Product;
use App\Models\Store;
use App\Models\Inventory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $site;
    protected $shift;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup Roles
        $superAdminRole = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);

        // Setup Users
        $this->adminUser = User::create(['email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->adminUser->roles()->attach($superAdminRole);

        // Associate site to admin user employee
        $this->site = Site::create(['site_name' => 'Test Mine Site', 'is_active' => true]);

        Employee::create([
            'employee_code' => 'EMP-ADM',
            'name' => 'Admin Employee',
            'joining_date' => '2026-01-01',
            'is_active' => true,
            'role_user_id' => RoleUser::create(['user_id' => $this->adminUser->id, 'role_id' => $superAdminRole->id])->id,
            'site_id' => $this->site->id,
        ]);

        $this->shift = Shift::create([
            'shift_name' => 'Day Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'is_night_shift' => 0,
            'minimum_working_hours' => 8,
        ]);
    }

    public function test_unauthenticated_cannot_access_dashboard_summary()
    {
        $response = $this->getJson('/api/v1/dashboard/summary');
        $response->assertStatus(401);
    }

    public function test_authenticated_retrieves_dashboard_summary_with_resolved_filters()
    {
        Sanctum::actingAs($this->adminUser);

        // Clear cache
        Cache::flush();

        // Seed Users and Employees
        $superAdminRole = Role::where('slug', 'super-admin')->first();

        $supervisorUser = User::create([
            'email' => 'supervisor_test@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $supervisor = Employee::create([
            'employee_code' => 'EMP-SUP-1',
            'name' => 'Supervisor One',
            'joining_date' => '2026-01-01',
            'is_active' => true,
            'role_user_id' => RoleUser::create(['user_id' => $supervisorUser->id, 'role_id' => $superAdminRole->id])->id,
        ]);

        $inchargeUser = User::create([
            'email' => 'incharge_test@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $incharge = Employee::create([
            'employee_code' => 'EMP-INC-1',
            'name' => 'Incharge One',
            'joining_date' => '2026-01-01',
            'is_active' => true,
            'role_user_id' => RoleUser::create(['user_id' => $inchargeUser->id, 'role_id' => $superAdminRole->id])->id,
        ]);

        $driver = Employee::create([
            'employee_code' => 'EMP-DRV-1',
            'name' => 'Driver One',
            'joining_date' => '2026-01-01',
            'is_active' => true,
        ]);

        // Seed Points
        $loadingPoint = SitePoint::create([
            'site_id' => $this->site->id,
            'name' => 'LP-1',
            'type' => 'loading',
            'is_active' => true,
            'created_by' => $this->adminUser->id,
        ]);

        $dumpingPoint = SitePoint::create([
            'site_id' => $this->site->id,
            'name' => 'DP-1',
            'type' => 'dumping',
            'is_active' => true,
            'created_by' => $this->adminUser->id,
        ]);

        // Seed Breakdown Type
        $breakdownType = BreakdownType::create([
            'breakdown_type' => 'Engine Overheat',
            'description' => 'Engine heat issue',
            'is_active' => true,
        ]);

        // Seed data
        // 1. ShiftPlan
        $shiftPlan = ShiftPlan::create([
            'reference_no' => 'SP-YEST-01',
            'planning_date' => Carbon::yesterday()->toDateString(),
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 1500.00,
            'status' => 'completed',
            'supervisor_id' => $supervisorUser->id,
            'site_incharge_id' => $inchargeUser->id,
            'created_by' => $this->adminUser->id,
        ]);

        // 2. Equipment Allocations
        $dumperCat = Equipment::create(['name' => 'Dumper', 'is_active' => true]);
        $excCat = Equipment::create(['name' => 'Excavator', 'is_active' => true]);
        $dumper1 = EquipmentName::create(['equipment_name' => 'DMP-01', 'equipment_id' => $dumperCat->id, 'is_active' => true]);
        $exc1 = EquipmentName::create(['equipment_name' => 'EXC-01', 'equipment_id' => $excCat->id, 'is_active' => true]);

        $alloc1 = ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $dumper1->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => Carbon::now(),
        ]);

        // 3. Dispatch Trips
        DispatchTrip::create([
            'trip_reference_no' => 'TRP-001',
            'shift_plan_id' => $shiftPlan->id,
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'mine_site_id' => $this->site->id, // populated field
            'dumper_equipment_id' => $dumper1->id,
            'driver_id' => $driver->id,
            'excavator_equipment_id' => $exc1->id,
            'loading_point_id' => $loadingPoint->id,
            'dumping_point_id' => $dumpingPoint->id,
            'trip_date_time' => Carbon::yesterday()->setTime(9, 0),
            'start_time' => Carbon::yesterday()->setTime(9, 0),
            'end_time' => Carbon::yesterday()->setTime(9, 10),
            'cycle_time_minutes' => 10.00,
            'quantity_bcm' => 120.00,
            'total_cycles' => 1,
            'status' => 'completed',
            'created_by' => $this->adminUser->id,
        ]);

        // 4. Fuel Consumed
        FuelEntry::create([
            'fuel_ref_no' => 'FL-001',
            'shift_plan_id' => $shiftPlan->id,
            'mine_site_id' => $this->site->id, // populated field
            'fuel_log_date' => Carbon::yesterday()->setTime(8, 30),
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
            'mine_site_id' => $this->site->id, // populated field
            'shift_date' => Carbon::yesterday()->toDateString(),
            'shift_name' => 'Day Shift',
            'delay_log_date' => Carbon::yesterday()->setTime(10, 0),
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
            'mine_site_id' => $this->site->id, // populated field
            'equipment_id' => $dumperCat->id,
            'equipment_name_id' => $dumper1->id,
            'equipment_allocation_id' => $alloc1->id,
            'breakdown_date_time' => Carbon::yesterday()->setTime(11, 0),
            'reported_by' => $driver->id,
            'breakdown_type_id' => $breakdownType->id,
            'severity' => 'MEDIUM',
            'description' => 'Breakdown description',
            'status' => 'closed',
            'downtime_start' => Carbon::yesterday()->setTime(11, 0),
            'downtime_end' => Carbon::yesterday()->setTime(11, 45),
            'downtime_minutes' => 45,
            'resolved_by' => $this->adminUser->id,
            'resolved_at' => Carbon::yesterday()->setTime(11, 45),
        ]);

        $response = $this->getJson('/api/v1/dashboard/summary?date_range=yesterday');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'overview' => [
                        'kpis' => [
                            'production_today',
                            'ob_removal',
                            'eqpt_running',
                            'breakdowns_count',
                            'total_trips',
                            'fuel_efficiency'
                        ],
                        'ob_milestone_progress' => [
                            'target',
                            'actual',
                            'percentage'
                        ],
                        'equipment_health' => [
                            'availability_percent',
                            'downtime_hours'
                        ],
                        'charts' => [
                            'production_vs_target',
                            'shift_performance',
                            'fleet_productivity'
                        ],
                        'top_performing_excavators',
                        'top_performing_dumpers',
                        'shift_delay_analysis' => [
                            'total_delay_minutes',
                            'categories',
                            'comparison_text'
                        ]
                    ],
                    'fuel' => [
                        'kpis' => [
                            'total_fuel_issued',
                            'total_fuel_consumed',
                            'fuel_efficiency',
                            'machines_refueled'
                        ],
                        'charts' => [
                            'consumption_trend',
                            'by_machine_type',
                            'efficiency_by_machine'
                        ]
                    ],
                    'breakdown' => [
                        'kpis' => [
                            'total_breakdown_events',
                            'total_downtime_hours',
                            'avg_downtime_per_breakdown',
                            'affected_machines'
                        ],
                        'charts' => [
                            'breakdown_trend',
                            'downtime_trend',
                            'category_analysis',
                            'machine_ranking'
                        ]
                    ],
                    'delay' => [
                        'kpis' => [
                            'total_delay_events',
                            'total_delay_hours',
                            'avg_delay_duration',
                            'production_loss_impact'
                        ],
                        'charts' => [
                            'delay_trend',
                            'shift_delays_vs_operational',
                            'by_category'
                        ]
                    ],
                    'dispatch' => [
                        'kpis' => [
                            'total_trips',
                            'total_bcm_moved',
                            'avg_payload_per_trip',
                            'avg_cycle_time'
                        ],
                        'charts' => [
                            'production_trend',
                            'cycle_time_distribution',
                            'dumper_vs_excavator_productivity'
                        ]
                    ]
                ]
            ]);

        $this->assertTrue($response->json('status'));
    }

    public function test_lazy_loaded_table_endpoints()
    {
        Sanctum::actingAs($this->adminUser);

        // 1. fuel/top-consumers
        $res = $this->getJson('/api/v1/dashboard/fuel/top-consumers?date_range=yesterday');
        $res->assertStatus(200)->assertJsonStructure(['status', 'message', 'data' => ['items', 'current_page', 'last_page', 'total', 'per_page']]);

        // 2. fuel/low-efficiency
        $res = $this->getJson('/api/v1/dashboard/fuel/low-efficiency?date_range=yesterday');
        $res->assertStatus(200)->assertJsonStructure(['status', 'message', 'data' => ['items', 'current_page', 'last_page', 'total', 'per_page']]);

        // 3. fuel/recent-transactions
        $res = $this->getJson('/api/v1/dashboard/fuel/recent-transactions?date_range=yesterday');
        $res->assertStatus(200)->assertJsonStructure(['status', 'message', 'data' => ['items', 'current_page', 'last_page', 'total', 'per_page']]);

        // 4. delay/top-categories
        $res = $this->getJson('/api/v1/dashboard/delay/top-categories?date_range=yesterday');
        $res->assertStatus(200)->assertJsonStructure(['status', 'message', 'data' => ['items', 'current_page', 'last_page', 'total', 'per_page']]);

        // 5. delay/critical-delays
        $res = $this->getJson('/api/v1/dashboard/delay/critical-delays?date_range=yesterday');
        $res->assertStatus(200)->assertJsonStructure(['status', 'message', 'data' => ['items', 'current_page', 'last_page', 'total', 'per_page']]);

        // 6. delay/recent-delays
        $res = $this->getJson('/api/v1/dashboard/delay/recent-delays?date_range=yesterday');
        $res->assertStatus(200)->assertJsonStructure(['status', 'message', 'data' => ['items', 'current_page', 'last_page', 'total', 'per_page']]);

        // 7. dispatch/recent-trips
        $res = $this->getJson('/api/v1/dashboard/dispatch/recent-trips?date_range=yesterday');
        $res->assertStatus(200)->assertJsonStructure(['status', 'message', 'data' => ['items', 'current_page', 'last_page', 'total', 'per_page']]);
    }

    public function test_summary_includes_live_inventory_stock()
    {
        Sanctum::actingAs($this->adminUser);

        $category = Category::create(['name' => 'Spares', 'is_active' => 1]);
        $subCategory = SubCategory::create(['category_id' => $category->id, 'name' => 'Valves', 'is_active' => 1]);
        $valve = Product::create(['sub_category_id' => $subCategory->id, 'name' => 'DI Sluice Valve', 'min_stock' => 20, 'is_active' => 1]);
        $tape = Product::create(['sub_category_id' => $subCategory->id, 'name' => 'B-tape', 'min_stock' => 10, 'is_active' => 1]);
        $udaipur = Store::create(['name' => 'Udaipur', 'is_active' => 1]);
        $kamalpur = Store::create(['name' => 'Kamalpur', 'is_active' => 1]);

        // in stock, below min level, out of stock (zero), out of stock (negative)
        Inventory::create(['store_id' => $udaipur->id, 'product_id' => $valve->id, 'quantity' => 50, 'left_quantity' => 50]);
        Inventory::create(['store_id' => $kamalpur->id, 'product_id' => $valve->id, 'quantity' => 30, 'left_quantity' => 7]);
        Inventory::create(['store_id' => $udaipur->id, 'product_id' => $tape->id, 'quantity' => 10, 'left_quantity' => 0]);
        Inventory::create(['store_id' => $kamalpur->id, 'product_id' => $tape->id, 'quantity' => 10, 'left_quantity' => -3]);

        // A past range still returns today's stock.
        $response = $this->getJson('/api/v1/dashboard/summary?date_range=last_30_days');

        $response->assertStatus(200)
            ->assertJsonPath('data.inventory.kpis.total_products', 4)
            ->assertJsonPath('data.inventory.kpis.below_min_level', 1)
            ->assertJsonPath('data.inventory.kpis.out_of_stock', 2)
            ->assertJsonPath('data.inventory.below_min_level_products.total', 1)
            ->assertJsonPath('data.inventory.below_min_level_products.items.0.product', 'DI Sluice Valve')
            ->assertJsonPath('data.inventory.below_min_level_products.items.0.location', 'Kamalpur')
            ->assertJsonPath('data.inventory.below_min_level_products.items.0.minimum_stock', 20)
            ->assertJsonPath('data.inventory.below_min_level_products.items.0.quantity', 7)
            ->assertJsonPath('data.inventory.out_of_stock_products.total', 2)
            ->assertJsonStructure(['data' => ['inventory' => ['out_of_stock_products' => [
                'items' => [['inventory_id', 'product_id', 'product', 'store_id', 'location', 'minimum_stock', 'quantity', 'stock_status', 'stock_status_label']],
                'current_page', 'last_page', 'total', 'per_page',
            ]]]]);

        // store_id narrows every inventory figure to one store.
        $this->getJson('/api/v1/dashboard/summary?store_id=' . $udaipur->id)
            ->assertStatus(200)
            ->assertJsonPath('data.inventory.kpis.total_products', 2)
            ->assertJsonPath('data.inventory.kpis.below_min_level', 0)
            ->assertJsonPath('data.inventory.kpis.out_of_stock', 1);

        // Lazy-loaded pages for the two tables.
        $this->getJson('/api/v1/dashboard/inventory/out-of-stock?per_page=1&page=2')
            ->assertStatus(200)
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonCount(1, 'data.items');

        $this->getJson('/api/v1/dashboard/inventory/below-min-level')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1);
    }

    public function test_custom_date_range_validation()
    {
        Sanctum::actingAs($this->adminUser);

        // 1. Valid custom range
        $res = $this->getJson('/api/v1/dashboard/summary?date_range=custom&date_from=2026-07-01&date_to=2026-07-02');
        $res->assertStatus(200);

        // 2. Missing date_from
        $res = $this->getJson('/api/v1/dashboard/summary?date_range=custom&date_to=2026-07-02');
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['date_from']);

        // 3. Range exceeding 1 year
        $res = $this->getJson('/api/v1/dashboard/summary?date_range=custom&date_from=2025-01-01&date_to=2026-07-01');
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }
}
