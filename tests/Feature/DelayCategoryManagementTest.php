<?php

namespace Tests\Feature;

use App\Models\DelayCategory;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DelayCategoryManagementTest extends TestCase
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

    public function test_super_admin_can_create_delay_category()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'delay_category' => 'Weather Delay',
            'description'    => 'Delays due to heavy rain, wind, or storm.'
        ];

        $response = $this->postJson('/api/v1/admin/delay-categories', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 200,
                'message' => 'Delay category created successfully'
            ]);

        $this->assertDatabaseHas('delay_categories', [
            'delay_category' => 'Weather Delay',
            'description'    => 'Delays due to heavy rain, wind, or storm.',
            'is_active'      => 1
        ]);
    }

    public function test_cannot_create_duplicate_delay_category()
    {
        Sanctum::actingAs($this->adminUser);

        DelayCategory::create([
            'delay_category' => 'Weather Delay',
            'description'    => 'Old'
        ]);

        $payload = [
            'delay_category' => 'Weather Delay',
            'description'    => 'New'
        ];

        $response = $this->postJson('/api/v1/admin/delay-categories', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('delay_category');
    }

    public function test_can_update_delay_category()
    {
        Sanctum::actingAs($this->adminUser);

        $category = DelayCategory::create([
            'delay_category' => 'Weather Delay',
            'description'    => 'Old description'
        ]);

        $payload = [
            'delay_category' => 'Weather Delay Updated',
            'description'    => 'New description'
        ];

        $response = $this->putJson("/api/v1/admin/delay-categories/{$category->id}", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 200,
                'message' => 'Delay category updated successfully'
            ]);

        $this->assertDatabaseHas('delay_categories', [
            'id'             => $category->id,
            'delay_category' => 'Weather Delay Updated',
            'description'    => 'New description'
        ]);
    }

    public function test_can_update_without_changing_name()
    {
        Sanctum::actingAs($this->adminUser);

        $category = DelayCategory::create([
            'delay_category' => 'Weather Delay',
            'description'    => 'Old description'
        ]);

        $payload = [
            'delay_category' => 'Weather Delay',
            'description'    => 'New description'
        ];

        $response = $this->putJson("/api/v1/admin/delay-categories/{$category->id}", $payload);

        $response->assertStatus(200);
    }

    public function test_can_retrieve_delay_category_details()
    {
        Sanctum::actingAs($this->adminUser);

        $category = DelayCategory::create([
            'delay_category' => 'Weather Delay',
            'description'    => 'Heavy storm delays'
        ]);

        $response = $this->getJson("/api/v1/admin/delay-categories/{$category->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'id',
                    'delay_category',
                    'description',
                    'status',
                ]
            ]);
    }

    public function test_can_toggle_delay_category_status()
    {
        Sanctum::actingAs($this->adminUser);

        $category = DelayCategory::create([
            'delay_category' => 'Weather Delay',
            'is_active'      => 1
        ]);

        $response = $this->patchJson("/api/v1/admin/delay-categories/{$category->id}/status", [
            'status' => '0'
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('delay_categories', [
            'id'        => $category->id,
            'is_active' => 0
        ]);
    }

    public function test_non_admin_cannot_manage_delay_categories()
    {
        Sanctum::actingAs($this->workerUser);

        $response = $this->postJson('/api/v1/admin/delay-categories', [
            'delay_category' => 'Weather Delay'
        ]);

        $response->assertStatus(403);
    }

    public function test_can_get_public_delay_categories()
    {
        Sanctum::actingAs($this->workerUser);

        DelayCategory::create([
            'delay_category' => 'Weather Delay',
            'is_active'      => 1
        ]);

        $response = $this->getJson('/api/v1/delay-categories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'delay_category',
                        'description',
                        'status',
                    ]
                ]
            ]);
    }
}
