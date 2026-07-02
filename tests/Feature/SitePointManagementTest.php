<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SitePoint;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SitePointManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $workerUser;
    protected $site;

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

        // Create Site
        $this->site = Site::create([
            'site_name' => 'Test Mine Site',
            'address' => '123 Mine Road',
            'is_active' => 1,
        ]);
    }

    public function test_super_admin_can_create_site_point()
    {
        Sanctum::actingAs($this->adminUser);

        $payload = [
            'site_id'     => $this->site->id,
            'name'        => 'Loading Point Alpha',
            'type'        => 'loading',
            'description' => 'Primary loading point near section A.',
            'latitude'    => 23.4567890,
            'longitude'   => 85.1234567,
        ];

        $response = $this->postJson('/api/v1/admin/site-points', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 201,
                'message' => 'Site point created successfully.'
            ]);

        $this->assertDatabaseHas('site_points', [
            'site_id'     => $this->site->id,
            'name'        => 'Loading Point Alpha',
            'type'        => 'loading',
            'description' => 'Primary loading point near section A.',
            'latitude'    => 23.4567890,
            'longitude'   => 85.1234567,
            'is_active'   => 1,
            'created_by'  => $this->adminUser->id,
        ]);
    }

    public function test_cannot_create_site_point_without_required_fields()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson('/api/v1/admin/site-points', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['site_id', 'name', 'type']);
    }

    public function test_can_update_site_point()
    {
        Sanctum::actingAs($this->adminUser);

        $point = SitePoint::create([
            'site_id'     => $this->site->id,
            'name'        => 'Dumping Point B',
            'type'        => 'dumping',
            'description' => 'Old description',
            'latitude'    => 24.1234,
            'longitude'   => 86.5678,
            'created_by'  => $this->adminUser->id,
        ]);

        $payload = [
            'name'        => 'Dumping Point B Updated',
            'description' => 'New description',
        ];

        $response = $this->putJson("/api/v1/admin/site-points/{$point->id}", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 200,
                'message' => 'Site point updated successfully.'
            ]);

        $this->assertDatabaseHas('site_points', [
            'id'          => $point->id,
            'name'        => 'Dumping Point B Updated',
            'description' => 'New description',
        ]);
    }

    public function test_can_retrieve_site_point_details()
    {
        Sanctum::actingAs($this->adminUser);

        $point = SitePoint::create([
            'site_id'     => $this->site->id,
            'name'        => 'Dumping Point C',
            'type'        => 'dumping',
            'created_by'  => $this->adminUser->id,
        ]);

        $response = $this->getJson("/api/v1/admin/site-points/{$point->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'id',
                    'site_id',
                    'site_name',
                    'name',
                    'type',
                    'description',
                    'latitude',
                    'longitude',
                    'is_active',
                    'created_by',
                    'creator_name',
                ]
            ]);
    }

    public function test_can_toggle_site_point_status()
    {
        Sanctum::actingAs($this->adminUser);

        $point = SitePoint::create([
            'site_id'     => $this->site->id,
            'name'        => 'Status Point',
            'type'        => 'loading',
            'is_active'   => true,
            'created_by'  => $this->adminUser->id,
        ]);

        $response = $this->patchJson("/api/v1/admin/site-points/{$point->id}/status");

        $response->assertStatus(200);

        $this->assertDatabaseHas('site_points', [
            'id'        => $point->id,
            'is_active' => 0,
        ]);
    }

    public function test_non_admin_cannot_manage_site_points()
    {
        Sanctum::actingAs($this->workerUser);

        $response = $this->postJson('/api/v1/admin/site-points', [
            'site_id'     => $this->site->id,
            'name'        => 'Unauthorized Point',
            'type'        => 'loading',
        ]);

        $response->assertStatus(403);
    }

    public function test_can_get_public_site_points()
    {
        Sanctum::actingAs($this->workerUser);

        SitePoint::create([
            'site_id'     => $this->site->id,
            'name'        => 'Active Public Point',
            'type'        => 'loading',
            'is_active'   => true,
        ]);

        SitePoint::create([
            'site_id'     => $this->site->id,
            'name'        => 'Inactive Public Point',
            'type'        => 'dumping',
            'is_active'   => false,
        ]);

        $response = $this->getJson('/api/v1/site-points');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'name' => 'Active Public Point',
            ]);
    }
}
