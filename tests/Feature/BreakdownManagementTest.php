<?php

namespace Tests\Feature;

use App\Models\BreakdownTicket;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\Shift;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BreakdownManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $workerUser;
    protected $shift;
    protected $equipment;
    protected $equipmentName;
    protected $allocation;
    protected $employee;
    protected $breakdownType1;
    protected $breakdownType2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Roles
        $adminRole = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);
        $workerRole = Role::create(['name' => 'Worker', 'slug' => 'worker', 'is_active' => 1]);

        // Create Users
        $this->adminUser = User::create(['email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->adminUser->roles()->attach($adminRole);

        $this->workerUser = User::create(['email' => 'worker@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->workerUser->roles()->attach($workerRole);

        // Create Shift
        $this->shift = Shift::create([
            'shift_name'            => 'Day Shift',
            'start_time'            => '08:00:00',
            'end_time'              => '16:00:00',
            'minimum_working_hours' => 8,
            'is_night_shift'        => 0,
        ]);

        // Create Equipment
        $this->equipment = Equipment::create([
            'name'      => 'Excavator EX01',
            'is_active' => 1,
        ]);

        // Create EquipmentName
        $this->equipmentName = EquipmentName::create([
            'equipment_id'   => $this->equipment->id,
            'equipment_name' => 'EX01-Excavator-CAT',
            'is_active'      => 1,
        ]);

        // Create Site
        $site = \App\Models\Site::create([
            'site_name' => 'Test Site',
            'is_active' => 1,
        ]);

        // Create ShiftPlan
        $shiftPlan = \App\Models\ShiftPlan::create([
            'planning_date' => '2026-06-26',
            'shift_id' => $this->shift->id,
            'site_id' => $site->id,
            'target_bcm' => 1000,
            'supervisor_id' => $this->adminUser->id,
            'site_incharge_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TEST-001',
        ]);

        // Create ShiftEquipmentAllocation
        $this->allocation = \App\Models\ShiftEquipmentAllocation::create([
            'shift_plan_id' => $shiftPlan->id,
            'equipment_name_id' => $this->equipmentName->id,
            'allocated_by' => $this->adminUser->id,
            'allocation_time' => now(),
        ]);

        // Create Employees matching the user IDs so they pass the validation check
        \DB::table('employees')->insert([
            [
                'id' => $this->adminUser->id,
                'employee_code' => 'EMP_ADM',
                'name' => 'Admin Employee',
                'joining_date' => '2026-01-01',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $this->workerUser->id,
                'employee_code' => 'EMP_WRK',
                'name' => 'Worker Employee',
                'joining_date' => '2026-01-01',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // Create BreakdownTypes with IDs 1 and 2
        \DB::table('breakdown_types')->insert([
            ['id' => 1, 'breakdown_type' => 'Mechanical', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'breakdown_type' => 'Electrical', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_non_admin_roles_cannot_write_breakdowns()
    {
        Sanctum::actingAs($this->workerUser);

        $payload = [
            'shift_id'            => $this->shift->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'HIGH',
            'description'         => 'Test breakdown description.',
            'downtime_start'      => '2026-06-26 10:00:00',
        ];

        $response = $this->postJson('/api/v1/admin/maintenance/breakdowns', $payload);
        $response->assertStatus(403);
    }

    public function test_can_create_breakdown_ticket()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'shift_id'                => $this->shift->id,
            'breakdown_date_time'     => '2026-06-26 12:00:00',
            'reported_by'             => $this->adminUser->id,
            'equipment_id'            => $this->equipment->id,
            'equipment_name_id'       => $this->equipmentName->id,
            'equipment_allocation_id' => $this->allocation->id,
            'breakdown_type_id'       => 2,
            'severity'                => 'CRITICAL',
            'description'             => 'Hydraulic hose leak.',
            'downtime_start'          => '2026-06-26 10:00:00',
        ];

        $response = $this->postJson('/api/v1/admin/maintenance/breakdowns', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'id',
                    'ticket_number',
                    'shift_id',
                    'shift_name',
                    'equipment_id',
                    'equipment_category',
                    'equipment_name_id',
                    'equipment_name',
                    'equipment_allocation_id',
                    'breakdown_date_time',
                    'reported_by',
                    'breakdown_type_id',
                    'breakdown_type',
                    'brek_down_type',
                    'severity',
                    'description',
                    'status',
                    'downtime_start',
                    'downtime_end',
                    'downtime_minutes',
                ]
            ]);

        $ticketNumber = $response->json('data.ticket_number');
        $this->assertStringStartsWith('BRK-' . date('Y') . '-', $ticketNumber);

        $this->assertDatabaseHas('breakdown_tickets', [
            'ticket_number'           => $ticketNumber,
            'status'                  => 'open',
            'severity'                => 'CRITICAL',
            'equipment_allocation_id' => $this->allocation->id,
        ]);
    }

    public function test_can_update_breakdown_ticket_status_to_closed()
    {
        Sanctum::actingAs($this->adminUser);

        $ticket = BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00001',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'Testing update',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 10:00:00',
        ]);

        $payload = [
            'status'           => 'closed',
            'downtime_end'     => '2026-06-26 12:30:00',
            'resolution_notes' => 'Fixed the leak.',
        ];

        $response = $this->putJson("/api/v1/admin/maintenance/breakdowns/{$ticket->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status'           => 'closed',
                'downtime_minutes' => 150, // 10:00 to 12:30 is 150 minutes
                'resolution_notes' => 'Fixed the leak.',
            ]);

        $this->assertDatabaseHas('breakdown_tickets', [
            'id'               => $ticket->id,
            'status'           => 'closed',
            'downtime_minutes' => 150,
            'resolved_by'      => $this->adminUser->id,
        ]);
    }

    public function test_can_update_breakdown_ticket_via_post_with_method_spoofing()
    {
        Sanctum::actingAs($this->adminUser);

        $ticket = BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00009',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'Testing update via POST',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 10:00:00',
        ]);

        $payload = [
            '_method'          => 'PUT',
            'status'           => 'closed',
            'downtime_end'     => '2026-06-26 12:30:00',
            'resolution_notes' => 'Fixed the leak via POST.',
        ];

        $response = $this->postJson("/api/v1/admin/maintenance/breakdowns/{$ticket->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status'           => 'closed',
                'downtime_minutes' => 150,
                'resolution_notes' => 'Fixed the leak via POST.',
            ]);

        $this->assertDatabaseHas('breakdown_tickets', [
            'id'               => $ticket->id,
            'status'           => 'closed',
            'downtime_minutes' => 150,
            'resolved_by'      => $this->adminUser->id,
        ]);
    }

    public function test_can_update_breakdown_ticket_by_providing_only_downtime_end()
    {
        Sanctum::actingAs($this->adminUser);

        $ticket = BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00008',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'Testing update with only downtime_end',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 10:00:00',
        ]);

        $payload = [
            'downtime_end'     => '2026-06-26 12:30:00',
            'resolution_notes' => 'Fixed the leak with only downtime_end.',
        ];

        $response = $this->putJson("/api/v1/admin/maintenance/breakdowns/{$ticket->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status'           => 'closed',
                'downtime_minutes' => 150,
                'resolution_notes' => 'Fixed the leak with only downtime_end.',
            ]);

        $this->assertDatabaseHas('breakdown_tickets', [
            'id'               => $ticket->id,
            'status'           => 'closed',
            'downtime_minutes' => 150,
            'resolved_by'      => $this->adminUser->id,
        ]);
    }

    public function test_cannot_edit_already_closed_ticket()
    {
        Sanctum::actingAs($this->adminUser);

        $ticket = BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00002',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'Already closed',
            'status'              => 'closed',
            'downtime_start'      => '2026-06-26 10:00:00',
            'downtime_end'        => '2026-06-26 11:00:00',
            'downtime_minutes'    => 60,
        ]);

        $payload = [
            'severity' => 'HIGH',
        ];

        $response = $this->putJson("/api/v1/admin/maintenance/breakdowns/{$ticket->id}", $payload);

        $response->assertStatus(403)
            ->assertJsonFragment([
                'status'  => 403,
                'message' => 'Closed incidents cannot be edited.',
            ]);
    }

    public function test_validation_downtime_end_after_downtime_start()
    {
        Sanctum::actingAs($this->adminUser);

        $ticket = BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00003',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'Testing end validation',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 10:00:00',
        ]);

        $payload = [
            'status'           => 'closed',
            'downtime_end'     => '2026-06-26 09:30:00', // before start
            'resolution_notes' => 'Invalid timing.',
        ];

        $response = $this->putJson("/api/v1/admin/maintenance/breakdowns/{$ticket->id}", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('downtime_end');
    }

    public function test_can_list_and_filter_breakdowns_with_dashboard_kpis()
    {
        Sanctum::actingAs($this->adminUser);

        // Ticket 1: Closed, 60 minutes downtime
        BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00010',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'Closed ticket',
            'status'              => 'closed',
            'downtime_start'      => '2026-06-26 10:00:00',
            'downtime_end'        => '2026-06-26 11:00:00',
            'downtime_minutes'    => 60,
            'resolved_by'         => $this->adminUser->id,
            'resolved_at'         => '2026-06-26 11:15:00', // MTTR: 10:00 to 11:15 is 75 mins = 1.25 hours
        ]);

        // Ticket 2: Open
        BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00011',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'HIGH',
            'description'         => 'Open ticket',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 11:00:00',
        ]);

        $response = $this->getJson('/api/v1/admin/maintenance/breakdowns');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'dashboard' => [
                    'open_tickets',
                    'closed_today',
                    'total_downtime_hours',
                    'mttr_hours',
                    'equipment_availability_percent',
                ],
                'data' => [
                    '*' => [
                        'id',
                        'ticket_number',
                        'shift_id',
                        'shift_name',
                        'equipment_id',
                        'equipment_category',
                        'equipment_name_id',
                        'equipment_name',
                        'equipment_allocation_id',
                        'breakdown_date_time',
                        'severity',
                        'status',
                        'downtime_start',
                        'downtime_end',
                        'downtime_minutes',
                        'reported_by_name',
                        'breakdown_type_id',
                        'breakdown_type',
                        'brek_down_type',
                        'resolved_at',
                    ]
                ],
                'pagination',
            ])
            ->assertJsonFragment([
                'open_tickets'         => 1,
                'total_downtime_hours' => 1.00, // 60 minutes / 60
                'mttr_hours'           => 1.00, // 60 minutes = 1.00 hours
            ]);
    }

    public function test_can_create_already_resolved_breakdown_ticket()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'shift_id'            => $this->shift->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_type_id'   => 2,
            'severity'            => 'CRITICAL',
            'description'         => 'Hydraulic hose leak.',
            'downtime_start'      => '2026-06-26 10:00:00',
            'downtime_end'        => '2026-06-26 11:30:00', // 90 minutes
            'resolution_notes'    => 'Replaced the hose.',
        ];

        $response = $this->postJson('/api/v1/admin/maintenance/breakdowns', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'status'           => 'closed',
                'downtime_minutes' => 90,
                'resolution_notes' => 'Replaced the hose.',
            ]);

        $this->assertDatabaseHas('breakdown_tickets', [
            'status'           => 'closed',
            'downtime_minutes' => 90,
            'resolved_by'      => $this->adminUser->id,
        ]);
    }

    public function test_show_with_invalid_id_returns_404()
    {
        Sanctum::actingAs($this->adminUser);
        $response = $this->getJson('/api/v1/admin/maintenance/breakdowns/undefined');
        $response->assertStatus(404);
    }

    public function test_update_with_invalid_id_returns_404()
    {
        Sanctum::actingAs($this->adminUser);
        $payload = [
            'status'           => 'closed',
            'downtime_end'     => '2026-06-26 12:30:00',
            'resolution_notes' => 'Fixed the leak.',
        ];
        $response = $this->putJson('/api/v1/admin/maintenance/breakdowns/undefined', $payload);
        $response->assertStatus(404);
    }

    public function test_can_create_breakdown_ticket_without_downtime_start()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'shift_id'            => $this->shift->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_type_id'   => 2,
            'severity'            => 'HIGH',
            'description'         => 'Test without downtime start.',
        ];

        $response = $this->postJson('/api/v1/admin/maintenance/breakdowns', $payload);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.downtime_start'));

        $this->assertDatabaseHas('breakdown_tickets', [
            'ticket_number'  => $response->json('data.ticket_number'),
            'downtime_start' => null,
            'status'         => 'open',
        ]);
    }

    public function test_cannot_close_ticket_without_downtime_start_if_not_provided()
    {
        Sanctum::actingAs($this->adminUser);

        $ticket = BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00021',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'Open ticket without downtime start',
            'status'              => 'open',
            'downtime_start'      => null,
        ]);

        $payload = [
            'status'           => 'closed',
            'downtime_end'     => '2026-06-26 12:30:00',
            'resolution_notes' => 'Fixed the leak.',
        ];

        $response = $this->putJson("/api/v1/admin/maintenance/breakdowns/{$ticket->id}", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['downtime_start']);
    }

    public function test_can_close_ticket_without_downtime_start_by_providing_it_during_update()
    {
        Sanctum::actingAs($this->adminUser);

        $ticket = BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00022',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'Open ticket without downtime start',
            'status'              => 'open',
            'downtime_start'      => null,
        ]);

        $payload = [
            'status'           => 'closed',
            'downtime_start'   => '2026-06-26 10:00:00',
            'downtime_end'     => '2026-06-26 12:30:00',
            'resolution_notes' => 'Fixed the leak.',
        ];

        $response = $this->putJson("/api/v1/admin/maintenance/breakdowns/{$ticket->id}", $payload);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'status'           => 'closed',
                'downtime_minutes' => 150,
                'resolution_notes' => 'Fixed the leak.',
            ]);

        $this->assertDatabaseHas('breakdown_tickets', [
            'id'               => $ticket->id,
            'status'           => 'closed',
            'downtime_start'   => '2026-06-26 10:00:00',
            'downtime_minutes' => 150,
        ]);
    }

    public function test_list_breakdowns_can_filter_by_breakdown_type_id()
    {
        Sanctum::actingAs($this->adminUser);

        // 1. Create a breakdown ticket of type 1 (Mechanical)
        BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00030',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'Mechanical issue',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 12:00:00',
        ]);

        // 2. Create a breakdown ticket of type 2 (Electrical)
        BreakdownTicket::create([
            'ticket_number'       => 'BRK-2026-00031',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 13:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 2,
            'severity'            => 'HIGH',
            'description'         => 'Electrical issue',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 13:00:00',
        ]);

        // 3. Filter by breakdown_type_id = 1 (Mechanical)
        $responseType1 = $this->getJson('/api/v1/admin/maintenance/breakdowns?breakdown_type_id=1');
        $responseType1->assertStatus(200);
        $dataType1 = $responseType1->json('data');
        $this->assertCount(1, $dataType1);
        $this->assertEquals(1, $dataType1[0]['breakdown_type_id']);

        // 4. Filter by breakdown_type_id = 2 (Electrical)
        $responseType2 = $this->getJson('/api/v1/admin/maintenance/breakdowns?breakdown_type_id=2');
        $responseType2->assertStatus(200);
        $dataType2 = $responseType2->json('data');
        $this->assertCount(1, $dataType2);
        $this->assertEquals(2, $dataType2[0]['breakdown_type_id']);
    }

    public function test_list_breakdowns_sorted_by_newest_first()
    {
        Sanctum::actingAs($this->adminUser);

        // 1. Create ticket A
        $ticketA = BreakdownTicket::create([
            'ticket_number'       => 'BRK-ORDER-001',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'First ticket',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 12:00:00',
        ]);
        $ticketA->created_at = '2026-06-26 12:00:00';
        $ticketA->save();

        // 2. Create ticket B (newer)
        $ticketB = BreakdownTicket::create([
            'ticket_number'       => 'BRK-ORDER-002',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $this->equipmentName->id,
            'breakdown_date_time' => '2026-06-26 13:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'HIGH',
            'description'         => 'Second ticket',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 13:00:00',
        ]);
        $ticketB->created_at = '2026-06-26 13:00:00';
        $ticketB->save();

        $response = $this->getJson('/api/v1/admin/maintenance/breakdowns');
        $response->assertStatus(200);
        $data = $response->json('data');

        // Assert ticket B is first in the list
        $this->assertEquals('BRK-ORDER-002', $data[0]['ticket_number']);
        $this->assertEquals('BRK-ORDER-001', $data[1]['ticket_number']);
    }

    public function test_list_breakdowns_can_search_by_equipment_name()
    {
        Sanctum::actingAs($this->adminUser);

        // Create two new EquipmentNames
        $equip1 = EquipmentName::create([
            'equipment_id'   => $this->equipment->id,
            'equipment_name' => 'KOMATSU-PC200',
            'is_active'      => 1,
        ]);

        $equip2 = EquipmentName::create([
            'equipment_id'   => $this->equipment->id,
            'equipment_name' => 'VOLVO-EC300',
            'is_active'      => 1,
        ]);

        // Create breakdown tickets for each
        BreakdownTicket::create([
            'ticket_number'       => 'BRK-KOMATSU',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $equip1->id,
            'breakdown_date_time' => '2026-06-26 12:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'MEDIUM',
            'description'         => 'Komatsu issue',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 12:00:00',
        ]);

        BreakdownTicket::create([
            'ticket_number'       => 'BRK-VOLVO',
            'shift_id'            => $this->shift->id,
            'equipment_id'        => $this->equipment->id,
            'equipment_name_id'   => $equip2->id,
            'breakdown_date_time' => '2026-06-26 13:00:00',
            'reported_by'         => $this->adminUser->id,
            'breakdown_type_id'   => 1,
            'severity'            => 'HIGH',
            'description'         => 'Volvo issue',
            'status'              => 'open',
            'downtime_start'      => '2026-06-26 13:00:00',
        ]);

        // Search for "KOMATSU"
        $response1 = $this->getJson('/api/v1/admin/maintenance/breakdowns?search=KOMATSU');
        $response1->assertStatus(200);
        $data1 = $response1->json('data');
        $this->assertCount(1, $data1);
        $this->assertEquals('BRK-KOMATSU', $data1[0]['ticket_number']);

        // Search for "VOLVO"
        $response2 = $this->getJson('/api/v1/admin/maintenance/breakdowns?search=VOLVO');
        $response2->assertStatus(200);
        $data2 = $response2->json('data');
        $this->assertCount(1, $data2);
        $this->assertEquals('BRK-VOLVO', $data2[0]['ticket_number']);
    }
}
