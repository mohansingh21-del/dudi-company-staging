<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the super-admin role
        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        // Create super-admin user
        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($role);

        // Authenticate with Sanctum
        Sanctum::actingAs($this->adminUser);
    }

    public function test_can_list_public_sites()
    {
        Site::create([
            'site_name' => 'Active Site',
            'address' => '123 Active St',
            'is_active' => 1
        ]);

        Site::create([
            'site_name' => 'Inactive Site',
            'address' => '456 Inactive St',
            'is_active' => 0
        ]);

        $response = $this->getJson('/api/v1/sites');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'name' => 'Active Site',
                'address' => '123 Active St',
                'status' => 1
            ]);
    }
}
