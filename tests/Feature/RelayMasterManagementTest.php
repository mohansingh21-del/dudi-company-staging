<?php

namespace Tests\Feature;

use App\Models\Relay;
use App\Models\Shift;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RelayMasterManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($role);

        Sanctum::actingAs($this->adminUser);

        $this->shift = Shift::create([
            'shift_name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);
    }

    public function test_can_create_relay_with_shift_mapping()
    {
        $response = $this->postJson('/api/v1/admin/relays', [
            'name' => 'New Test Relay',
            'is_rotating' => true,
            'is_active' => true,
            'shift_id' => $this->shift->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'name' => 'New Test Relay',
            'shift_id' => $this->shift->id,
            'shift_name' => 'Morning Shift',
        ]);

        $relay = Relay::where('name', 'New Test Relay')->first();
        $this->assertNotNull($relay);

        $this->assertDatabaseHas('relay_shift_mappings', [
            'relay_id' => $relay->id,
            'shift_id' => $this->shift->id,
        ]);
    }

    public function test_can_create_relay_without_shift_id()
    {
        $response = $this->postJson('/api/v1/admin/relays', [
            'name' => 'Relay Without Shift',
            'is_rotating' => true,
            'is_active' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'name' => 'Relay Without Shift',
            'shift_id' => null,
            'shift_name' => null,
        ]);

        $relay = Relay::where('name', 'Relay Without Shift')->first();
        $this->assertNotNull($relay);

        $this->assertDatabaseMissing('relay_shift_mappings', [
            'relay_id' => $relay->id,
        ]);
    }

    public function test_can_update_relay_shift_mapping()
    {
        $relay = Relay::create([
            'name' => 'Existing Relay',
            'is_rotating' => true,
            'is_active' => true,
        ]);

        $newShift = Shift::create([
            'shift_name' => 'Night Shift',
            'start_time' => '20:00:00',
            'end_time' => '04:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 1,
            'is_active' => 1
        ]);

        $response = $this->putJson("/api/v1/admin/relays/{$relay->id}", [
            'name' => 'Updated Relay Name',
            'shift_id' => $newShift->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'name' => 'Updated Relay Name',
            'shift_id' => $newShift->id,
            'shift_name' => 'Night Shift',
        ]);

        $this->assertDatabaseHas('relay_shift_mappings', [
            'relay_id' => $relay->id,
            'shift_id' => $newShift->id,
        ]);
    }

    public function test_can_toggle_relay_status()
    {
        $relay = Relay::create([
            'name' => 'Toggle Relay',
            'is_rotating' => true,
            'is_active' => true,
        ]);

        $response = $this->patchJson("/api/v1/admin/relays/{$relay->id}/status");
        $response->assertStatus(200);
        $this->assertFalse($relay->fresh()->is_active);

        $response = $this->patchJson("/api/v1/admin/relays/{$relay->id}/status");
        $response->assertStatus(200);
        $this->assertTrue($relay->fresh()->is_active);
    }

    public function test_cannot_create_relay_with_already_mapped_rotating_shift()
    {
        // 1. Create a relay with Morning Shift mapped for this week
        $relay1 = Relay::create([
            'name' => 'Relay 1',
            'is_rotating' => true,
            'is_active' => true,
        ]);

        $today = now();
        $weekStart = $today->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
        $weekEnd = $today->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(6)->toDateString();

        \App\Models\RelayShiftMapping::create([
            'week_start_date' => $weekStart,
            'week_end_date' => $weekEnd,
            'relay_id' => $relay1->id,
            'shift_id' => $this->shift->id,
        ]);

        // 2. Try to create another rotating relay with the same shift_id
        $response = $this->postJson('/api/v1/admin/relays', [
            'name' => 'Relay 2',
            'is_rotating' => true,
            'is_active' => true,
            'shift_id' => $this->shift->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('shift_id');
    }

    public function test_cannot_update_relay_with_already_mapped_rotating_shift()
    {
        // 1. Create Relay 1 with Morning Shift mapped
        $relay1 = Relay::create([
            'name' => 'Relay 1',
            'is_rotating' => true,
            'is_active' => true,
        ]);

        $today = now();
        $weekStart = $today->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
        $weekEnd = $today->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(6)->toDateString();

        \App\Models\RelayShiftMapping::create([
            'week_start_date' => $weekStart,
            'week_end_date' => $weekEnd,
            'relay_id' => $relay1->id,
            'shift_id' => $this->shift->id,
        ]);

        // 2. Create Relay 2
        $relay2 = Relay::create([
            'name' => 'Relay 2',
            'is_rotating' => true,
            'is_active' => true,
        ]);

        // 3. Try to update Relay 2 to the same shift
        $response = $this->putJson("/api/v1/admin/relays/{$relay2->id}", [
            'name' => 'Relay 2 Updated',
            'shift_id' => $this->shift->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('shift_id');
    }
}
