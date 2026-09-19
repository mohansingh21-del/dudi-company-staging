<?php

namespace Tests\Feature;

use App\Models\IncidentType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncidentTypeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);

        $this->adminUser->roles()->attach($adminRole);

        Sanctum::actingAs($this->adminUser);
    }

    public function test_cannot_create_incident_type_with_too_long_name()
    {
        $response = $this->postJson('/api/v1/admin/incident-types', [
            'incident_type' => str_repeat('A', 256),
            'description'   => 'Overlong name'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('incident_type');

        $this->assertDatabaseCount('incident_types', 0);
    }

    public function test_cannot_create_incident_type_with_too_long_description()
    {
        $response = $this->postJson('/api/v1/admin/incident-types', [
            'incident_type' => 'Roof Fall',
            'description'   => str_repeat('B', 1001)
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('description');
    }

    public function test_can_create_incident_type_at_max_name_length()
    {
        $name = str_repeat('C', 255);

        $response = $this->postJson('/api/v1/admin/incident-types', [
            'incident_type' => $name,
            'description'   => 'Boundary case'
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('incident_types', [
            'incident_type' => $name
        ]);
    }

    public function test_cannot_update_incident_type_with_too_long_name()
    {
        $type = IncidentType::create([
            'incident_type' => 'Roof Fall',
            'description'   => 'Original'
        ]);

        $response = $this->putJson(
            '/api/v1/admin/incident-types/' . $type->id,
            ['incident_type' => str_repeat('D', 256)]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('incident_type');

        $this->assertDatabaseHas('incident_types', [
            'id' => $type->id,
            'incident_type' => 'Roof Fall'
        ]);
    }

    public function test_can_update_incident_type_keeping_its_own_name()
    {
        $type = IncidentType::create([
            'incident_type' => 'Roof Fall',
            'description'   => 'Original'
        ]);

        $response = $this->putJson(
            '/api/v1/admin/incident-types/' . $type->id,
            [
                'incident_type' => 'Roof Fall',
                'description'   => 'Updated'
            ]
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('incident_types', [
            'id' => $type->id,
            'description' => 'Updated'
        ]);
    }

    public function test_cannot_update_incident_type_to_an_existing_name()
    {
        IncidentType::create(['incident_type' => 'Roof Fall']);

        $type = IncidentType::create(['incident_type' => 'Gas Leak']);

        $response = $this->putJson(
            '/api/v1/admin/incident-types/' . $type->id,
            ['incident_type' => 'Roof Fall']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('incident_type');
    }
}
