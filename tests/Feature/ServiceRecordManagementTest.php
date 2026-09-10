<?php

namespace Tests\Feature;

use App\Models\Breakdown;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceRecord;
use App\Models\Site;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\Category;
use App\Models\User;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceRecordManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $machine;
    protected $site;
    protected $product;
    protected $inventory;
    protected $store;
    protected $inventory2;
    protected $product2;
    protected $product3;
    protected $inventory3;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name'      => 'System-Administrator',
            'slug'      => 'super-admin',
            'is_active' => 1
        ]);

        $this->adminUser = User::create([
            'name'      => 'Admin User',
            'email'     => 'admin@test.com',
            'password'  => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($role);

        Sanctum::actingAs($this->adminUser);

        $equipment = Equipment::create(['name' => 'Excavator', 'is_active' => 1]);
        $this->machine = EquipmentName::create([
            'equipment_id'   => $equipment->id,
            'equipment_name' => 'EXC-001',
            'is_active'      => 1
        ]);

        $this->site = Site::create(['site_name' => 'Site Alpha', 'is_active' => 1]);

        $category = Category::create(['name' => 'Spare Parts']);
        $subCategory = SubCategory::create(['category_id' => $category->id, 'name' => 'Filters']);
        $this->product = Product::create([
            'sub_category_id' => $subCategory->id,
            'name'            => 'Oil Filter XP-90',
            'min_stock'       => 5,
            'is_active'       => 1
        ]);

        $this->store = Store::create([
            'name'        => 'ABC Traders',
            'description' => 'Spare parts store',
            'is_active'   => 1,
        ]);

        $this->inventory = Inventory::create([
            'store_id'      => $this->store->id,
            'product_id'    => $this->product->id,
            'quantity'      => 50.00,
            'left_quantity' => 20.00,
            'is_active'     => 1,
        ]);

        // Further products at the same store, for records that draw more than
        // one part. A record draws from one store, so they all live here.
        $this->product2 = Product::create([
            'sub_category_id' => $subCategory->id,
            'name'            => 'Hydraulic Hose HX-12',
            'min_stock'       => 0,
            'is_active'       => 1
        ]);

        $this->inventory2 = Inventory::create([
            'store_id'      => $this->store->id,
            'product_id'    => $this->product2->id,
            'quantity'      => 30.00,
            'left_quantity' => 30.00,
            'is_active'     => 1,
        ]);

        $this->product3 = Product::create([
            'sub_category_id' => $subCategory->id,
            'name'            => 'Air Filter AF-20',
            'min_stock'       => 0,
            'is_active'       => 1
        ]);

        $this->inventory3 = Inventory::create([
            'store_id'      => $this->store->id,
            'product_id'    => $this->product3->id,
            'quantity'      => 15.00,
            'left_quantity' => 15.00,
            'is_active'     => 1,
        ]);
    }

    public function test_can_create_general_service_record_with_recalculated_totals()
    {
        $payload = [
            'is_breakdown_service'   => false,
            'machine_id'             => $this->machine->id,
            'service_date'           => '2026-07-23',
            'hours_odometer_reading' => 1500.50,
            'km_run'                 => 12000.00,
            'base_service_amount'    => 500.00,
            'checklist'              => [
                'oil_change'          => true,
                'oil_change_amount'   => 150.00,
                'fuel_filter_change'  => true,
                'fuel_filter_change_amount' => 100.00,
            ],
            'store_id'               => $this->store->id,
            'job_card_number'        => 'JC-2026-0001',
            'spare_parts_changed'    => true,
            'spare_parts'            => [
                [
                    'inventory_id'         => $this->inventory->id,
                    'quantity'             => 2,
                    // One amount covering both units, not a per-unit rate.
                    'amount'               => 100.00,
                ],
                [
                    'inventory_id'     => $this->inventory2->id,
                    'quantity'         => 1,
                    'amount'           => 80.00,
                ]
            ],
            'remarks'                => 'Routine quarterly maintenance.'
        ];

        $response = $this->postJson('/api/v1/admin/service-records', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 201)
            ->assertJsonPath('data.is_breakdown_service', false)
            ->assertJsonPath('data.base_service_amount', '500.00')
            ->assertJsonPath('data.checklist_amount_total', '250.00')
            ->assertJsonPath('data.spare_parts_amount_total', '180.00')
            ->assertJsonPath('data.total_amount', '930.00');

        $this->assertDatabaseHas('service_records', [
            'machine_id'    => $this->machine->id,
            'total_amount'  => 930.00,
            'service_type'  => 'general',
        ]);

        // Assert stock deduction
        $this->assertDatabaseHas('inventories', [
            'product_id'    => $this->product->id,
            'left_quantity' => 18.00, // 20 - 2 = 18
        ]);
    }

    public function test_cannot_deduct_inventory_stock_below_minimum_stock()
    {
        // Available: 20, min_stock: 5. Deducting 18 leaves 2 (< 5), so it should fail validation.
        $payload = [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-23',
            'job_card_number'      => 'JC-2026-T000',
            'store_id'             => $this->store->id,
            'spare_parts_changed'  => true,
            'spare_parts'          => [
                [
                    'inventory_id'         => $this->inventory->id,
                    'quantity'             => 18,
                    'amount'               => 180.00,
                ]
            ],
        ];

        $response = $this->postJson('/api/v1/admin/service-records', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonPath('message', 'Validation failed');
    }

    public function test_can_create_breakdown_service_and_auto_resolve_breakdown_on_completed()
    {
        $shift = Shift::create(['shift_name' => 'Shift A', 'start_time' => '08:00', 'end_time' => '16:00']);
        $employee = \App\Models\Employee::create([
            'employee_code' => 'EMP-001',
            'name'          => 'John Operator',
            'joining_date'  => '2026-01-01',
            'basic_salary'  => 10000,
            'is_active'     => 1,
        ]);

        $breakdown = Breakdown::create([
            'ticket_number'       => 'BRK-2026-00001',
            'shift_id'            => $shift->id,
            'equipment_id'        => $this->machine->equipment_id,
            'equipment_name_id'   => $this->machine->id,
            'breakdown_date_time' => now()->subHours(3),
            'reported_by'         => $employee->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'HIGH',
            'description'         => 'Hydraulic leak',
            'status'              => 'open',
            'downtime_start'      => now()->subHours(3),
            'mine_site_id'        => $this->site->id,
        ]);

        $payload = [
            'is_breakdown_service' => true,
            'breakdown_id'         => $breakdown->id,
            'service_date'         => '2026-07-23',
            'job_card_number'      => 'JC-2026-T001',
            'base_service_amount'  => 300.00,
        ];

        $createResponse = $this->postJson('/api/v1/admin/service-records', $payload);
        $createResponse->assertStatus(201)
            ->assertJsonPath('data.service_type', 'repair')
            ->assertJsonPath('data.machine_id', $this->machine->id)
            ->assertJsonPath('data.site_id', $this->site->id);

        $recordId = $createResponse->json('data.id');
        $ticketNumber = $createResponse->json('data.ticket_number');

        // Completing without a downtime window is refused.
        $this->putJson("/api/v1/admin/service-records/{$recordId}", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('errors.downtime_end.0', 'Downtime start and downtime end are required to complete a service record.');

        $this->assertDatabaseHas('breakdown_tickets', [
            'id'     => $breakdown->id,
            'status' => 'open',
        ]);

        // Recording the downtime window completes the service on its own.
        $updateResponse = $this->putJson("/api/v1/admin/service-records/{$recordId}", [
            'downtime_start' => '08:00',
            'downtime_end'   => '12:30',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.downtime_minutes', 270);

        // The linked breakdown closes and inherits the same window.
        $this->assertDatabaseHas('breakdown_tickets', [
            'id'               => $breakdown->id,
            'status'           => 'closed',
            'downtime_start'   => '2026-07-23 08:00:00',
            'downtime_end'     => '2026-07-23 12:30:00',
            'downtime_minutes' => 270,
            'resolution_notes' => 'Resolved via Service Record Ticket #' . $ticketNumber,
        ]);
    }

    public function test_downtime_on_create_completes_service_and_closes_breakdown()
    {
        $shift = Shift::create(['shift_name' => 'Shift A', 'start_time' => '08:00', 'end_time' => '16:00']);
        $employee = \App\Models\Employee::create([
            'employee_code' => 'EMP-001',
            'name'          => 'John Operator',
            'joining_date'  => '2026-01-01',
            'basic_salary'  => 10000,
            'is_active'     => 1,
        ]);

        $breakdown = Breakdown::create([
            'ticket_number'       => 'BRK-2026-00001',
            'shift_id'            => $shift->id,
            'equipment_id'        => $this->machine->equipment_id,
            'equipment_name_id'   => $this->machine->id,
            'breakdown_date_time' => '2026-07-27 07:00:00',
            'reported_by'         => $employee->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'HIGH',
            'description'         => 'Hydraulic leak',
            'status'              => 'open',
            'mine_site_id'        => $this->site->id,
        ]);

        $response = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => true,
            'breakdown_id'         => $breakdown->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T002',
            'downtime_start'       => '07:15',
            'downtime_end'         => '09:45',
            'base_service_amount'  => 300.00,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.downtime_minutes', 150);

        $this->assertDatabaseHas('breakdown_tickets', [
            'id'               => $breakdown->id,
            'status'           => 'closed',
            'downtime_minutes' => 150,
        ]);
    }

    public function test_downtime_completes_a_non_breakdown_service()
    {
        $response = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T003',
            'downtime_start'       => '10:00:00',
            'downtime_end'         => '11:00:00',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.downtime_minutes', 60);

        // Times are stored against service_date.
        $this->assertDatabaseHas('service_records', [
            'id'             => $response->json('data.id'),
            'downtime_start' => '2026-07-27 10:00:00',
            'downtime_end'   => '2026-07-27 11:00:00',
        ]);

        // Without downtime it stays pending.
        $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T004',
        ])->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.downtime_minutes', null);
    }

    public function test_downtime_time_validation()
    {
        // End without a start.
        $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T005',
            'downtime_end'         => '11:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['downtime_start']);

        // Identical times record no downtime at all.
        $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T006',
            'downtime_start'       => '11:00',
            'downtime_end'         => '11:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['downtime_end']);

        // A full datetime is no longer accepted — times only.
        $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T007',
            'downtime_start'       => '2026-07-27 11:00:00',
            'downtime_end'         => '2026-07-27 12:00:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['downtime_start', 'downtime_end']);

        // Nonsense times are rejected.
        $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T008',
            'downtime_start'       => '25:99',
            'downtime_end'         => '12:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['downtime_start']);
    }

    public function test_downtime_spanning_midnight_rolls_to_next_day()
    {
        // A night shift repair from 22:00 to 02:00 is 4 hours, not negative.
        $response = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T009',
            'downtime_start'       => '22:00',
            'downtime_end'         => '02:00',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.downtime_minutes', 240);

        $this->assertDatabaseHas('service_records', [
            'id'             => $response->json('data.id'),
            'downtime_start' => '2026-07-27 22:00:00',
            'downtime_end'   => '2026-07-28 02:00:00',
        ]);
    }

    public function test_breakdown_ticket_cannot_be_closed_directly()
    {
        $shift = Shift::create(['shift_name' => 'Shift A', 'start_time' => '08:00', 'end_time' => '16:00']);
        $employee = \App\Models\Employee::create([
            'employee_code' => 'EMP-001',
            'name'          => 'John Operator',
            'joining_date'  => '2026-01-01',
            'basic_salary'  => 10000,
            'is_active'     => 1,
        ]);

        $breakdown = Breakdown::create([
            'ticket_number'       => 'BRK-2026-00001',
            'shift_id'            => $shift->id,
            'equipment_id'        => $this->machine->equipment_id,
            'equipment_name_id'   => $this->machine->id,
            'breakdown_date_time' => '2026-07-27 07:00:00',
            'reported_by'         => $employee->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'HIGH',
            'description'         => 'Hydraulic leak',
            'status'              => 'open',
            'mine_site_id'        => $this->site->id,
        ]);

        $this->putJson("/api/v1/admin/maintenance/breakdowns/{$breakdown->id}", ['status' => 'closed'])
            ->assertStatus(422)
            ->assertJsonPath('status', 422);

        $this->assertDatabaseHas('breakdown_tickets', [
            'id'     => $breakdown->id,
            'status' => 'open',
        ]);

        // Non-closing transitions still work.
        $this->putJson("/api/v1/admin/maintenance/breakdowns/{$breakdown->id}", ['status' => 'in_progress'])
            ->assertStatus(200);
    }

    public function test_can_fetch_history_and_audit_trail()
    {
        $payload = [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-23',
            'job_card_number'      => 'JC-2026-T010',
        ];

        $createRes = $this->postJson('/api/v1/admin/service-records', $payload);
        $recordId = $createRes->json('data.id');

        // Fetch index with pagination
        $indexRes = $this->getJson("/api/v1/admin/service-records");
        $indexRes->assertStatus(200)
            ->assertJsonPath('status', 200)
            ->assertJsonStructure([
                'status',
                'message',
                'data',
                'pagination' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);

        // Fetch history for machine
        $historyRes = $this->getJson("/api/v1/admin/service-records/machine/{$this->machine->id}/history");
        $historyRes->assertStatus(200)
            ->assertJsonPath('status', 200);

        // Fetch audit trail
        $auditRes = $this->getJson("/api/v1/admin/service-records/{$recordId}/audit-trail");
        $auditRes->assertStatus(200)
            ->assertJsonPath('status', 200);
    }

    public function test_detailed_service_report()
    {
        $created = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'site_id'              => $this->site->id,
            'service_date'         => '2026-07-20',
            'base_service_amount'  => 5000,
            'store_id'             => $this->store->id,
            'job_card_number'      => 'JC-2026-0042',
            'spare_parts_changed'  => true,
            'spare_parts'          => [
                ['inventory_id' => $this->inventory->id, 'quantity' => 2],
                ['inventory_id' => $this->inventory2->id, 'quantity' => 1, 'amount' => 5200],
            ],
        ]);
        $created->assertStatus(201);

        $id = $created->json('data.id');
        \DB::table('service_records')->where('id', $id)->update(['service_type' => 'repair']);

        $report = $this->getJson("/api/v1/admin/service-records/{$id}");

        $report->assertStatus(200)
            ->assertJsonPath('data.service_type_label', 'Repair')
            ->assertJsonPath('data.service_date', '2026-07-20')
            ->assertJsonPath('data.machine.name', 'EXC-001');

        // Replacements & Consumables — nothing was changed on this service.
        $report->assertJsonPath('data.checklist.oil_change.done', false)
            ->assertJsonPath('data.checklist.hydraulic_oil.done', false)
            ->assertJsonPath('data.checklist.gear_oil.done', false)
            ->assertJsonPath('data.checklist.filters.changed', []);

        // Spare Parts Used — every part is labelled with the store it came
        // from, and its name is resolved from the shared product catalog
        // rather than typed in free text. An unpriced issue must not read as ₹0.
        $report->assertJsonCount(2, 'data.spare_parts')
            ->assertJsonPath('data.spare_parts.0.source_label', 'ABC Traders')
            ->assertJsonPath('data.spare_parts.0.store_id', $this->store->id)
            ->assertJsonPath('data.spare_parts.0.part_name', 'Oil Filter XP-90')
            ->assertJsonPath('data.spare_parts.0.quantity', 2)
            ->assertJsonPath('data.spare_parts.0.is_priced', false)
            ->assertJsonPath('data.spare_parts.1.source_label', 'ABC Traders')
            ->assertJsonPath('data.spare_parts.1.store_id', $this->store->id)
            ->assertJsonPath('data.spare_parts.1.part_name', 'Hydraulic Hose HX-12')
            ->assertJsonPath('data.spare_parts.1.amount', 5200)
            ->assertJsonPath('data.spare_parts.1.is_priced', true);

        // The record carries the one store and one job card its parts came from.
        $report->assertJsonPath('data.job_card_number', 'JC-2026-0042')
            ->assertJsonPath('data.store.id', $this->store->id)
            ->assertJsonPath('data.store.name', 'ABC Traders');

        $report->assertJsonPath('data.totals.base_service_amount', 5000)
            ->assertJsonPath('data.totals.spare_parts_amount_total', 5200)
            ->assertJsonPath('data.totals.total_amount', 10200);

        // Audit data lives on its own endpoint, not in the report.
        $this->assertArrayNotHasKey('audit_logs', $report->json('data'));
        $this->assertArrayNotHasKey('status_history', $report->json('data'));
    }

    public function test_detailed_service_report_groups_changed_filters()
    {
        $created = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-20',
            'job_card_number'      => 'JC-2026-T011',
            'base_service_amount'  => 1000,
            'checklist'            => [
                'oil_change'                => true,
                'oil_change_amount'         => 1200,
                'fuel_filter_change'        => true,
                'fuel_filter_change_amount' => 450,
                'oil_filter_change'         => true,
                'oil_filter_change_amount'  => 350,
            ],
        ]);
        $created->assertStatus(201);

        $this->getJson("/api/v1/admin/service-records/{$created->json('data.id')}")
            ->assertStatus(200)
            ->assertJsonPath('data.checklist.oil_change.done', true)
            ->assertJsonPath('data.checklist.oil_change.amount', 1200)
            ->assertJsonPath('data.checklist.gear_oil.done', false)
            ->assertJsonPath('data.checklist.filters.changed', ['Fuel Filter', 'Oil Filter'])
            ->assertJsonPath('data.totals.checklist_amount_total', 2000)
            ->assertJsonPath('data.totals.total_amount', 3000);
    }

    public function test_machine_service_history_returns_summary_and_timeline()
    {
        // Repair, completed, two spare parts.
        $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service'   => false,
            'machine_id'             => $this->machine->id,
            'service_date'           => '2026-07-20',
            'hours_odometer_reading' => 450,
            'base_service_amount'    => 6600,
            'store_id'               => $this->store->id,
            'job_card_number'        => 'JC-2026-0077',
            'spare_parts_changed'    => true,
            'spare_parts'            => [
                ['inventory_id' => $this->inventory2->id, 'quantity' => 1, 'amount' => 2400],
                ['inventory_id' => $this->inventory3->id, 'quantity' => 1, 'amount' => 1200],
            ],
        ])->assertStatus(201);
        \DB::table('service_records')->where('hours_odometer_reading', 450)->update(['service_type' => 'repair']);

        // General, with an engine oil checklist item.
        $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service'   => false,
            'machine_id'             => $this->machine->id,
            'service_date'           => '2026-07-16',
            'job_card_number'        => 'JC-2026-T012',
            'hours_odometer_reading' => 5000,
            'base_service_amount'    => 3300,
            'checklist'              => ['oil_change' => true, 'oil_change_amount' => 1200],
        ])->assertStatus(201);

        // Cancelled work must not reach the cards or the timeline.
        $cancelled = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-18',
            'job_card_number'      => 'JC-2026-T013',
            'base_service_amount'  => 99999,
        ]);
        $this->putJson("/api/v1/admin/service-records/{$cancelled->json('data.id')}", ['status' => 'cancelled'])
            ->assertStatus(200);

        $response = $this->getJson("/api/v1/admin/service-records/machine/{$this->machine->id}/history");

        $response->assertStatus(200)
            ->assertJsonPath('data.machine.name', 'EXC-001')
            ->assertJsonPath('data.summary.total_expense', 10200 + 4500)
            ->assertJsonPath('data.summary.total_services', 2)
            ->assertJsonPath('data.summary.general_services', 1)
            ->assertJsonPath('data.summary.repair_jobs', 1)
            ->assertJsonCount(2, 'data.history');

        // Newest first.
        $response->assertJsonPath('data.history.0.service_date', '2026-07-20')
            ->assertJsonPath('data.history.0.service_type', 'repair')
            ->assertJsonPath('data.history.0.hours_odometer_reading', 450)
            ->assertJsonPath('data.history.0.total_amount', 10200)
            ->assertJsonPath('data.history.0.spare_parts_count', 2)
            ->assertJsonPath('data.history.0.checklist_items', []);

        $response->assertJsonPath('data.history.1.service_date', '2026-07-16')
            ->assertJsonPath('data.history.1.total_amount', 4500)
            ->assertJsonPath('data.history.1.checklist_items', ['Engine Oil']);

        // Filtering narrows the timeline but leaves the cards describing the machine.
        $filtered = $this->getJson("/api/v1/admin/service-records/machine/{$this->machine->id}/history?service_type=repair");
        $filtered->assertStatus(200)
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.service_type', 'repair')
            ->assertJsonPath('data.summary.total_services', 2);

        // Pagination is opt-in.
        $paged = $this->getJson("/api/v1/admin/service-records/machine/{$this->machine->id}/history?limit=1");
        $paged->assertStatus(200)
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.last_page', 2);
    }

    public function test_machine_service_history_is_empty_for_a_machine_never_serviced()
    {
        $response = $this->getJson("/api/v1/admin/service-records/machine/{$this->machine->id}/history");

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.total_expense', 0)
            ->assertJsonPath('data.summary.total_services', 0)
            ->assertJsonPath('data.summary.general_services', 0)
            ->assertJsonPath('data.summary.repair_jobs', 0)
            ->assertJsonCount(0, 'data.history');
    }

    public function test_machine_under_service_cannot_be_allocated_to_a_shift()
    {
        $shift = Shift::create(['shift_name' => 'Shift A', 'start_time' => '08:00', 'end_time' => '16:00']);
        $shiftPlan = \App\Models\ShiftPlan::create([
            'planning_date'    => '2026-07-28',
            'shift_id'         => $shift->id,
            'site_id'          => $this->site->id,
            'target_bcm'       => 1000,
            'supervisor_id'    => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'status'           => 'draft',
            'created_by'       => $this->adminUser->id,
            'reference_no'     => 'SP-2026-0001',
        ]);

        $categoryId = $this->machine->equipment_id;
        $availableUrl = "/api/v1/admin/shift-plans/{$shiftPlan->id}/equipment/available?category_id={$categoryId}";
        $allocateUrl = "/api/v1/admin/shift-plans/{$shiftPlan->id}/equipment";

        // Free to begin with.
        $this->getJson($availableUrl)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.machine_id', $this->machine->id);

        $created = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T014',
            'base_service_amount'  => 100.00,
        ]);
        $created->assertStatus(201);
        $recordId = $created->json('data.id');
        $ticket = $created->json('data.ticket_number');

        // Pending service takes it out of the available list...
        $this->getJson($availableUrl)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // ...and a direct allocate call is refused, naming the blocking ticket.
        $this->postJson($allocateUrl, ['machine_id' => $this->machine->id])
            ->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonPath('message', "Machine cannot be allocated as it is currently under service (Ticket: {$ticket}).");

        // Still blocked while the work is in progress.
        $this->putJson("/api/v1/admin/service-records/{$recordId}", ['status' => 'in_progress'])
            ->assertStatus(200);
        $this->getJson($availableUrl)->assertJsonCount(0, 'data');

        // Recording the downtime window completes the service and releases the machine.
        $this->putJson("/api/v1/admin/service-records/{$recordId}", [
            'downtime_start' => '08:00',
            'downtime_end'   => '10:00',
        ])->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->getJson($availableUrl)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->postJson($allocateUrl, ['machine_id' => $this->machine->id])
            ->assertStatus(201);
    }

    public function test_service_record_list_search_and_filters()
    {
        $otherEquipment = Equipment::create(['name' => 'Dumper', 'is_active' => 1]);
        $otherMachine = EquipmentName::create([
            'equipment_id'   => $otherEquipment->id,
            'equipment_name' => 'DMP-777',
            'is_active'      => 1
        ]);
        $otherSite = Site::create(['site_name' => 'Site Beta', 'is_active' => 1]);

        $first = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'site_id'              => $this->site->id,
            'service_date'         => '2026-07-20',
            'job_card_number'      => 'JC-2026-T015',
            'performed_by'         => 'Ramesh Kumar',
        ]);
        $first->assertStatus(201);
        $firstTicket = $first->json('data.ticket_number');

        $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $otherMachine->id,
            'site_id'              => $otherSite->id,
            'service_date'         => '2026-07-25',
            'job_card_number'      => 'JC-2026-T016',
            'performed_by'         => 'Anil Sharma',
        ])->assertStatus(201);

        // Machine name
        $this->getJson('/api/v1/admin/service-records?search=DMP')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.machine_name', 'DMP-777');

        // Site name
        $this->getJson('/api/v1/admin/service-records?search=Beta')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site_name', 'Site Beta');

        // Technician
        $this->getJson('/api/v1/admin/service-records?search=Ramesh')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.performed_by', 'Ramesh Kumar');

        // Ticket number
        $this->getJson("/api/v1/admin/service-records?search={$firstTicket}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ticket_number', $firstTicket);

        // Search stays ANDed with the other filters rather than widening them.
        $this->getJson('/api/v1/admin/service-records?search=Ramesh&date_from=2026-07-24')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination.total', 0);

        // No match is an empty list, not an error.
        $this->getJson('/api/v1/admin/service-records?search=zzz-nothing')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        // Absent search still returns everything.
        $this->getJson('/api/v1/admin/service-records')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_audit_trail_resolves_user_name_through_employee()
    {
        // The acting admin is linked to an employee record via role_user.
        $roleUser = \App\Models\RoleUser::where('user_id', $this->adminUser->id)->first();
        \App\Models\Employee::create([
            'role_user_id'  => $roleUser->id,
            'employee_code' => 'EMP-900',
            'name'          => 'Suresh Patil',
            'joining_date'  => '2026-01-01',
            'basic_salary'  => 25000,
            'is_active'     => 1,
        ]);

        $created = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T017',
            'base_service_amount'  => 100.00,
        ]);
        $created->assertStatus(201);
        $recordId = $created->json('data.id');

        $this->putJson("/api/v1/admin/service-records/{$recordId}", ['status' => 'in_progress'])
            ->assertStatus(200);

        $trail = $this->getJson("/api/v1/admin/service-records/{$recordId}/audit-trail");
        $trail->assertStatus(200);

        $entries = collect($trail->json('data'));
        $this->assertGreaterThan(0, $entries->count());

        // Every actor label resolves to the employee name, not null.
        foreach ($entries as $entry) {
            $actor = isset($entry['performed_by']) ? $entry['performed_by'] : $entry['changed_by'];
            $this->assertSame('Suresh Patil', $actor);
        }
    }

    public function test_audit_trail_falls_back_to_email_without_employee_record()
    {
        $created = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T018',
            'base_service_amount'  => 100.00,
        ]);
        $created->assertStatus(201);

        $trail = $this->getJson("/api/v1/admin/service-records/{$created->json('data.id')}/audit-trail");
        $trail->assertStatus(200)
            ->assertJsonPath('data.0.performed_by', 'admin@test.com');
    }

    public function test_open_breakdowns_returns_only_linkable_tickets()
    {
        $shift = Shift::create(['shift_name' => 'Shift A', 'start_time' => '08:00', 'end_time' => '16:00']);
        $employee = \App\Models\Employee::create([
            'employee_code' => 'EMP-001',
            'name'          => 'John Operator',
            'joining_date'  => '2026-01-01',
            'basic_salary'  => 10000,
            'is_active'     => 1,
        ]);

        $base = [
            'shift_id'            => $shift->id,
            'equipment_id'        => $this->machine->equipment_id,
            'equipment_name_id'   => $this->machine->id,
            'reported_by'         => $employee->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'HIGH',
            'description'         => 'Hydraulic leak',
            'downtime_start'      => now()->subHours(3),
            'mine_site_id'        => $this->site->id,
        ];

        Breakdown::create($base + [
            'ticket_number'       => 'BRK-2026-00001',
            'breakdown_date_time' => now()->subHours(3),
            'status'              => 'open',
        ]);
        Breakdown::create($base + [
            'ticket_number'       => 'BRK-2026-00002',
            'breakdown_date_time' => now()->subHours(5),
            'status'              => 'in_progress',
        ]);
        Breakdown::create($base + [
            'ticket_number'       => 'BRK-2026-00003',
            'breakdown_date_time' => now()->subHours(9),
            'status'              => 'closed',
        ]);

        // Defaults to every non-closed ticket, newest first.
        $response = $this->getJson('/api/v1/open-breakdowns');
        $response->assertStatus(200)
            ->assertJsonPath('status', 200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.ticket_number', 'BRK-2026-00001')
            ->assertJsonPath('data.1.ticket_number', 'BRK-2026-00002')
            ->assertJsonPath('data.0.equipment_name', 'EXC-001')
            ->assertJsonPath('data.0.mine_site_id', $this->site->id);

        // Narrowing to a single status.
        $this->getJson('/api/v1/open-breakdowns?status=open')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ticket_number', 'BRK-2026-00001');

        // A closed status is not a linkable option.
        $this->getJson('/api/v1/open-breakdowns?status=closed')
            ->assertStatus(422)
            ->assertJsonPath('status', 422);
    }

    public function test_breakdown_cannot_be_claimed_by_two_service_records()
    {
        $shift = Shift::create(['shift_name' => 'Shift A', 'start_time' => '08:00', 'end_time' => '16:00']);
        $employee = \App\Models\Employee::create([
            'employee_code' => 'EMP-001',
            'name'          => 'John Operator',
            'joining_date'  => '2026-01-01',
            'basic_salary'  => 10000,
            'is_active'     => 1,
        ]);

        $breakdown = Breakdown::create([
            'ticket_number'       => 'BRK-2026-00001',
            'shift_id'            => $shift->id,
            'equipment_id'        => $this->machine->equipment_id,
            'equipment_name_id'   => $this->machine->id,
            'breakdown_date_time' => now()->subHours(3),
            'reported_by'         => $employee->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'HIGH',
            'description'         => 'Hydraulic leak',
            'status'              => 'open',
            'downtime_start'      => now()->subHours(3),
            'mine_site_id'        => $this->site->id,
        ]);

        $payload = [
            'is_breakdown_service' => true,
            'breakdown_id'         => $breakdown->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T019',
            'base_service_amount'  => 300.00,
        ];

        // Selectable before anything claims it.
        $this->getJson('/api/v1/open-breakdowns')->assertJsonCount(1, 'data');

        $first = $this->postJson('/api/v1/admin/service-records', $payload);
        $first->assertStatus(201);
        $recordId = $first->json('data.id');

        // The pending record holds the ticket, so it drops out of the dropdown...
        $this->getJson('/api/v1/open-breakdowns')->assertJsonCount(0, 'data');

        // ...and the API refuses a second claim even if the client sends it anyway.
        $this->postJson('/api/v1/admin/service-records', $payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonPath('errors.breakdown_id.0', 'This breakdown ticket is already linked to service record SRV-2026-00001.');

        // Cancelling the record releases the ticket back to the dropdown.
        $this->putJson("/api/v1/admin/service-records/{$recordId}", ['status' => 'cancelled'])
            ->assertStatus(200);

        $this->getJson('/api/v1/open-breakdowns')->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/admin/service-records', $payload)->assertStatus(201);
    }

    public function test_available_products_excludes_stock_at_or_below_min_stock()
    {
        // Sitting exactly at min_stock: on the shelf, but not deductable.
        $atMinStock = Product::create([
            'sub_category_id' => $this->product->sub_category_id,
            'name'            => 'Gear Oil GX-10',
            'min_stock'       => 10,
            'is_active'       => 1
        ]);
        Inventory::create([
            'store_id'      => $this->store->id,
            'product_id'    => $atMinStock->id,
            'quantity'      => 30.00,
            'left_quantity' => 10.00,
            'is_active'     => 1,
        ]);

        // Active product not stocked anywhere.
        Product::create([
            'sub_category_id' => $this->product->sub_category_id,
            'name'            => 'Brake Pad BP-77',
            'min_stock'       => 2,
            'is_active'       => 1
        ]);

        $response = $this->getJson('/api/v1/available-products')
            ->assertStatus(200)
            ->assertJsonPath('status', 200);

        // The three stocked above their floor, and neither of the other two.
        $productIds = array_column($response->json('data'), 'product_id');
        sort($productIds);
        $expected = [$this->product->id, $this->product2->id, $this->product3->id];
        sort($expected);
        $this->assertSame($expected, $productIds);

        $oilFilter = collect($response->json('data'))
            ->firstWhere('product_id', $this->product->id);

        $this->assertSame($this->inventory->id, $oilFilter['inventory_id']);
        $this->assertSame($this->store->id, $oilFilter['store_id']);
        $this->assertEquals(20, $oilFilter['left_quantity']);
        $this->assertEquals(5, $oilFilter['min_stock']);
        $this->assertEquals(15, $oilFilter['available_quantity']);
    }

    public function test_can_create_service_record_from_multipart_string_booleans()
    {
        // multipart/form-data sends every value as a string, including booleans.
        $response = $this->post('/api/v1/admin/service-records', [
            'is_breakdown_service' => 'false',
            'machine_id'           => $this->machine->id,
            'site_id'              => $this->site->id,
            'service_date'         => '2026-07-27',
            'job_card_number'      => 'JC-2026-T020',
            'base_service_amount'  => '500.00',
            'performed_by'         => 'Ramesh Kumar',
            'checklist'            => [
                'oil_change'        => 'true',
                'oil_change_amount' => '150.00',
                'gear_oil'          => 'false',
            ],
            'spare_parts_changed'  => 'false',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('status', 201)
            ->assertJsonPath('data.is_breakdown_service', false)
            ->assertJsonPath('data.breakdown_id', null)
            ->assertJsonPath('data.site_id', $this->site->id)
            ->assertJsonPath('data.performed_by', 'Ramesh Kumar')
            ->assertJsonPath('data.total_amount', '650.00');
    }
}
