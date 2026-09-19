<?php

namespace Tests\Feature;

use App\Models\BreakdownType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BreakdownTypeValidationTest extends TestCase
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

    public function test_cannot_create_breakdown_type_with_too_long_name()
    {
        $response = $this->postJson('/api/v1/admin/breakdown-types', [
            'breakdown_type' => str_repeat('A', 256),
            'description'    => 'Overlong name'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('breakdown_type');

        $this->assertDatabaseCount('breakdown_types', 0);
    }

    public function test_cannot_create_breakdown_type_with_too_long_description()
    {
        $response = $this->postJson('/api/v1/admin/breakdown-types', [
            'breakdown_type' => 'Mechanical Failure',
            'description'    => str_repeat('B', 1001)
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('description');
    }

    public function test_can_create_breakdown_type_at_max_name_length()
    {
        $name = str_repeat('C', 255);

        $response = $this->postJson('/api/v1/admin/breakdown-types', [
            'breakdown_type' => $name,
            'description'    => 'Boundary case'
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('breakdown_types', [
            'breakdown_type' => $name
        ]);
    }

    public function test_cannot_update_breakdown_type_with_too_long_name()
    {
        $type = BreakdownType::create([
            'breakdown_type' => 'Mechanical Failure',
            'description'    => 'Original'
        ]);

        $response = $this->putJson(
            '/api/v1/admin/breakdown-types/' . $type->id,
            ['breakdown_type' => str_repeat('D', 256)]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('breakdown_type');

        $this->assertDatabaseHas('breakdown_types', [
            'id' => $type->id,
            'breakdown_type' => 'Mechanical Failure'
        ]);
    }

    public function test_can_update_breakdown_type_keeping_its_own_name()
    {
        $type = BreakdownType::create([
            'breakdown_type' => 'Mechanical Failure',
            'description'    => 'Original'
        ]);

        $response = $this->putJson(
            '/api/v1/admin/breakdown-types/' . $type->id,
            [
                'breakdown_type' => 'Mechanical Failure',
                'description'    => 'Updated'
            ]
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('breakdown_types', [
            'id' => $type->id,
            'description' => 'Updated'
        ]);
    }

    public function test_cannot_update_breakdown_type_to_an_existing_name()
    {
        BreakdownType::create(['breakdown_type' => 'Mechanical Failure']);

        $type = BreakdownType::create(['breakdown_type' => 'Electrical Fault']);

        $response = $this->putJson(
            '/api/v1/admin/breakdown-types/' . $type->id,
            ['breakdown_type' => 'Mechanical Failure']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('breakdown_type');
    }
}
