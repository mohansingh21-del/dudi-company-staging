<?php

namespace Tests\Feature;

use App\Models\BreakdownType;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BreakdownTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $workerUser;

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
    }

    public function test_super_admin_can_create_breakdown_type()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'breakdown_type' => 'Mechanical Failure',
            'description'    => 'Issues with engines, gears, hydraulics, etc.'
        ];

        $response = $this->postJson('/api/v1/admin/breakdown-types', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 200,
                'message' => 'Breakdown type created successfully'
            ]);

        $this->assertDatabaseHas('breakdown_types', [
            'breakdown_type' => 'Mechanical Failure',
            'description'    => 'Issues with engines, gears, hydraulics, etc.',
            'is_active'      => 1
        ]);
    }

    public function test_cannot_create_duplicate_breakdown_type()
    {
        Sanctum::actingAs($this->adminUser);

        BreakdownType::create([
            'breakdown_type' => 'Mechanical Failure',
            'description'    => 'Old'
        ]);

        $payload = [
            'breakdown_type' => 'Mechanical Failure',
            'description'    => 'New'
        ];

        $response = $this->postJson('/api/v1/admin/breakdown-types', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('breakdown_type');
    }

    public function test_can_update_breakdown_type()
    {
        Sanctum::actingAs($this->adminUser);

        $type = BreakdownType::create([
            'breakdown_type' => 'Mechanical Failure',
            'description'    => 'Old description'
        ]);

        $payload = [
            'breakdown_type' => 'Mechanical Failure Updated',
            'description'    => 'New description'
        ];

        $response = $this->putJson("/api/v1/admin/breakdown-types/{$type->id}", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 200,
                'message' => 'Breakdown type updated successfully'
            ]);

        $this->assertDatabaseHas('breakdown_types', [
            'id'             => $type->id,
            'breakdown_type' => 'Mechanical Failure Updated',
            'description'    => 'New description'
        ]);
    }

    public function test_can_update_without_changing_name()
    {
        Sanctum::actingAs($this->adminUser);

        $type = BreakdownType::create([
            'breakdown_type' => 'Mechanical Failure',
            'description'    => 'Old description'
        ]);

        $payload = [
            'breakdown_type' => 'Mechanical Failure',
            'description'    => 'New description'
        ];

        $response = $this->putJson("/api/v1/admin/breakdown-types/{$type->id}", $payload);

        $response->assertStatus(200);
    }

    public function test_can_retrieve_breakdown_type_details()
    {
        Sanctum::actingAs($this->adminUser);

        $type = BreakdownType::create([
            'breakdown_type' => 'Mechanical Failure',
            'description'    => 'Engine issues'
        ]);

        $response = $this->getJson("/api/v1/admin/breakdown-types/{$type->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'id',
                    'breakdown_type',
                    'description',
                    'status',
                ]
            ]);
    }

    public function test_can_toggle_breakdown_type_status()
    {
        Sanctum::actingAs($this->adminUser);

        $type = BreakdownType::create([
            'breakdown_type' => 'Mechanical Failure',
            'is_active'      => 1
        ]);

        $response = $this->patchJson("/api/v1/admin/breakdown-types/{$type->id}/status", [
            'status' => '0'
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('breakdown_types', [
            'id'        => $type->id,
            'is_active' => 0
        ]);
    }

    public function test_non_admin_cannot_manage_breakdown_types()
    {
        Sanctum::actingAs($this->workerUser);

        $response = $this->postJson('/api/v1/admin/breakdown-types', [
            'breakdown_type' => 'Mechanical Failure'
        ]);

        $response->assertStatus(403);
    }

    public function test_can_get_public_breakdown_types()
    {
        Sanctum::actingAs($this->workerUser);

        BreakdownType::create([
            'breakdown_type' => 'Mechanical Failure',
            'is_active'      => 1
        ]);

        $response = $this->getJson('/api/v1/breakdown-types');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'breakdown_type',
                        'description',
                        'status',
                    ]
                ]
            ]);
    }
}
