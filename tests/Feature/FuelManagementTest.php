<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\ShiftPlan;
use App\Models\ShiftEquipmentAllocation;
use App\Models\FuelEntry;
use App\Models\FuelEntryAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FuelManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $shift;
    protected $site;
    protected $equipment;
    protected $equipmentName;
    protected $publishedShiftPlan;
    protected $draftShiftPlan;
    protected $allocationPublished;
    protected $allocationDraft;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Roles
        $adminRole = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);

        // Create User
        $this->adminUser = User::create(['email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->adminUser->roles()->attach($adminRole);

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
            'supervisor_id' => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'created_by'    => $this->adminUser->id,
            'reference_no'  => 'SP-PUBLISHED-001',
        ]);

        // Create Draft Shift Plan
        $this->draftShiftPlan = ShiftPlan::create([
            'planning_date' => '2026-06-28',
            'shift_id'      => $this->shift->id,
            'site_id'       => $this->site->id,
            'target_bcm'    => 1000,
            'status'        => 'draft',
            'supervisor_id' => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'created_by'    => $this->adminUser->id,
            'reference_no'  => 'SP-DRAFT-001',
        ]);

        // Create Allocations
        $this->allocationPublished = ShiftEquipmentAllocation::create([
            'shift_plan_id'     => $this->publishedShiftPlan->id,
            'equipment_name_id' => $this->equipmentName->id,
            'allocated_by'      => $this->adminUser->id,
            'allocation_time'   => now(),
        ]);

        $this->allocationDraft = ShiftEquipmentAllocation::create([
            'shift_plan_id'     => $this->draftShiftPlan->id,
            'equipment_name_id' => $this->equipmentName->id,
            'allocated_by'      => $this->adminUser->id,
            'allocation_time'   => now(),
        ]);

        // Create Employees matching the user IDs
        \DB::table('employees')->insert([
            [
                'id'            => $this->adminUser->id,
                'employee_code' => 'EMP_ADM',
                'name'          => 'Admin Employee',
                'joining_date'  => '2026-01-01',
                'is_active'     => 1,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]
        ]);
    }

    public function test_can_create_fuel_entry_success()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
            'hours_meter_reading'     => 10.5,
            'kilometer_reading'       => 150.0,
            'remarks'                 => 'Initial refuel',
        ];

        $response = $this->postJson('/api/v1/admin/fuel-entries', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'id',
                    'fuel_ref_no',
                    'shift_plan_id',
                    'equipment_allocation_id',
                    'fuel_consumption',
                    'opening_fuel',
                    'fuel_issued',
                    'closing_fuel',
                ]
            ]);

        $fuelRefNo = $response->json('data.fuel_ref_no');
        $this->assertStringStartsWith('FUEL-' . date('Y') . '-', $fuelRefNo);

        $this->assertDatabaseHas('fuel_entries', [
            'fuel_ref_no'      => $fuelRefNo,
            'fuel_consumption' => 170.00, // 100 + 150 - 80
            'status'           => 'active',
        ]);
    }

    public function test_cannot_create_fuel_entry_if_shift_plan_draft()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'shift_plan_id'           => $this->draftShiftPlan->id,
            'equipment_allocation_id' => $this->allocationDraft->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
        ];

        $response = $this->postJson('/api/v1/admin/fuel-entries', $payload);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'status'  => 422,
                'message' => 'Machine Is Not Assigned To Current Shift',
            ]);
    }

    public function test_cannot_create_fuel_entry_if_machine_not_allocated()
    {
        Sanctum::actingAs($this->adminUser);

        // Try placing the draft allocation with the published shift plan (cross mismatch)
        $payload = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationDraft->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
        ];

        $response = $this->postJson('/api/v1/admin/fuel-entries', $payload);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'status'  => 422,
                'message' => 'Machine Is Not Assigned To Current Shift',
            ]);
    }

    public function test_cannot_create_fuel_entry_with_regression_readings()
    {
        Sanctum::actingAs($this->adminUser);

        // First entry
        $payload1 = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
            'hours_meter_reading'     => 100.0,
            'kilometer_reading'       => 1000.0,
        ];
        $this->postJson('/api/v1/admin/fuel-entries', $payload1)->assertStatus(201);

        // Second entry with regression in hour reading
        $payload2 = $payload1;
        $payload2['hours_meter_reading'] = 99.9;
        $payload2['kilometer_reading'] = 1001.0;

        $response = $this->postJson('/api/v1/admin/fuel-entries', $payload2);
        $response->assertStatus(422)
            ->assertJsonFragment([
                'status'  => 422,
                'message' => 'Hour Meter Reading Cannot Be Less Than Previous Reading',
            ]);

        // Second entry with regression in kilometer reading
        $payload3 = $payload1;
        $payload3['hours_meter_reading'] = 100.5;
        $payload3['kilometer_reading'] = 999.0;

        $response = $this->postJson('/api/v1/admin/fuel-entries', $payload3);
        $response->assertStatus(422)
            ->assertJsonFragment([
                'status'  => 422,
                'message' => 'Kilometer Reading Cannot Be Less Than Previous Reading',
            ]);
    }

    public function test_can_calculate_fuel_rates_correctly_on_subsequent_entries()
    {
        Sanctum::actingAs($this->adminUser);

        // First entry
        $payload1 = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 50.00,
            'closing_fuel'            => 120.00, // Consumption = 30
            'hours_meter_reading'     => 50.0,
            'kilometer_reading'       => 500.0,
        ];
        $this->postJson('/api/v1/admin/fuel-entries', $payload1)->assertStatus(201);

        // Second entry
        $payload2 = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 120.00,
            'fuel_issued'             => 100.00,
            'closing_fuel'            => 100.00, // Consumption = 120
            'hours_meter_reading'     => 55.0, // Diff = 5 hours
            'kilometer_reading'       => 620.0, // Diff = 120 km
        ];
        $response = $this->postJson('/api/v1/admin/fuel-entries', $payload2);
        
        $response->assertStatus(201);
        $this->assertDatabaseHas('fuel_entries', [
            'hours_meter_reading' => 55.0,
            'fuel_per_hour'       => 24.00, // 120 / 5
            'fuel_per_km'         => 1.00,  // 120 / 120
        ]);
    }

    public function test_can_update_fuel_entry_and_recalculate()
    {
        Sanctum::actingAs($this->adminUser);

        // Create
        $payload = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
            'hours_meter_reading'     => 10.0,
            'kilometer_reading'       => 100.0,
        ];
        $createResponse = $this->postJson('/api/v1/admin/fuel-entries', $payload);
        $id = $createResponse->json('data.id');

        // Update closing fuel
        $updatePayload = [
            'closing_fuel' => 70.00,
        ];
        $updateResponse = $this->putJson("/api/v1/admin/fuel-entries/{$id}", $updatePayload);

        $updateResponse->assertStatus(200);
        $this->assertDatabaseHas('fuel_entries', [
            'id'               => $id,
            'closing_fuel'     => 70.00,
            'fuel_consumption' => 180.00, // 100 + 150 - 70
        ]);

        // Verify audit log has the change
        $this->assertDatabaseHas('fuel_entry_audit_logs', [
            'fuel_entry_id' => $id,
        ]);
    }

    public function test_cannot_update_read_only_fields()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
        ];
        $createResponse = $this->postJson('/api/v1/admin/fuel-entries', $payload);
        $id = $createResponse->json('data.id');

        // Try updating shift_plan_id
        $updatePayload = [
            'shift_plan_id' => 999,
        ];
        $response = $this->putJson("/api/v1/admin/fuel-entries/{$id}", $updatePayload);
        $response->assertStatus(422)
            ->assertJsonFragment([
                'status'  => 422,
                'message' => 'Field shift_plan_id is read-only',
            ]);
    }

    public function test_cannot_update_voided_records()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
        ];
        $createResponse = $this->postJson('/api/v1/admin/fuel-entries', $payload);
        $id = $createResponse->json('data.id');

        // Void the record
        FuelEntry::where('id', $id)->update(['status' => 'voided']);

        // Try updating
        $updatePayload = [
            'remarks' => 'Modified',
        ];
        $response = $this->putJson("/api/v1/admin/fuel-entries/{$id}", $updatePayload);
        $response->assertStatus(422)
            ->assertJsonFragment([
                'status'  => 422,
                'message' => 'Voided records cannot be edited.',
            ]);
    }

    public function test_dashboard_analytics_reporting()
    {
        Sanctum::actingAs($this->adminUser);

        // Create fuel entry
        $payload = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
        ];
        $this->postJson('/api/v1/admin/fuel-entries', $payload)->assertStatus(201);

        $response = $this->getJson('/api/v1/admin/fuel-entries/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'kpi_cards' => [
                        'total_fuel_issued',
                        'total_fuel_consumption',
                        'fuel_efficiency_l_per_bcm',
                        'active_machines_count',
                    ],
                    'trend_chart',
                    'category_breakdown',
                    'machine_comparison',
                    'top_fuel_consuming_machines',
                    'lowest_efficiency_machines',
                    'recent_entries',
                ]
            ]);
    }

    public function test_performance_analytics_reporting()
    {
        Sanctum::actingAs($this->adminUser);

        // Create fuel entry
        $payload = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
        ];
        $this->postJson('/api/v1/admin/fuel-entries', $payload)->assertStatus(201);

        $response = $this->getJson('/api/v1/admin/fuel-entries/performance');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'kpi_cards' => [
                        'total_fuel_issued',
                        'total_fuel_consumption',
                        'fuel_efficiency_l_per_bcm',
                        'best_performer',
                        'worst_performer',
                    ],
                    'trend_chart',
                    'efficiency_trend',
                    'category_breakdown',
                    'machine_fuel_performance',
                ]
            ]);
    }

    public function test_allocation_tracking()
    {
        Sanctum::actingAs($this->adminUser);

        // Create fuel entry
        $payload = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
        ];
        $this->postJson('/api/v1/admin/fuel-entries', $payload)->assertStatus(201);

        $response = $this->getJson('/api/v1/admin/fuel-entries/allocation-tracking');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    '*' => [
                        'planning_date',
                        'shift_id',
                        'shift_name',
                        'equipment_name_id',
                        'equipment_name',
                        'fuel_allocated_liters',
                        'entry_count',
                    ]
                ],
                'pagination',
            ]);
    }

    public function test_summary_reporting()
    {
        Sanctum::actingAs($this->adminUser);

        // Create fuel entry
        $payload = [
            'shift_plan_id'           => $this->publishedShiftPlan->id,
            'equipment_allocation_id' => $this->allocationPublished->id,
            'operator_id'             => $this->adminUser->id,
            'fuel_source'             => 'fuel_tanker',
            'opening_fuel'            => 100.00,
            'fuel_issued'             => 150.00,
            'closing_fuel'            => 80.00,
        ];
        $this->postJson('/api/v1/admin/fuel-entries', $payload)->assertStatus(201);

        $response = $this->getJson('/api/v1/admin/fuel-entries/summary');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'machines' => [
                        '*' => [
                            'machine_id',
                            'machine_name',
                            'fuel_issued_liters',
                            'closing_fuel_liters',
                            'total_consumption_liters',
                        ]
                    ],
                    'totals' => [
                        'total_fuel_issued_liters',
                        'total_closing_fuel_liters',
                        'total_consumption_liters',
                    ]
                ]
            ]);
    }
}
