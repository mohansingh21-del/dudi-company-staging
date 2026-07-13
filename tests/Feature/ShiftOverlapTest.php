<?php

namespace Tests\Feature;

use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftOverlapTest extends TestCase
{
    use RefreshDatabase;

    private $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $role = \App\Models\Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $this->adminUser = \App\Models\User::create([
            'email' => 'admin_shift@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($role);

        \Laravel\Sanctum\Sanctum::actingAs($this->adminUser);
    }

    public function test_can_create_non_overlapping_shift()
    {
        // Create an existing shift: 08:00 to 16:00
        Shift::create([
            'shift_name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Attempt to create a new shift: 16:00 to 00:00 (adjacent, not overlapping)
        $response = $this->postJson('/api/v1/admin/shift', [
            'name' => 'Evening Shift',
            'start_time' => '16:00',
            'end_time' => '00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shifts', [
            'shift_name' => 'Evening Shift'
        ]);
    }

    public function test_can_create_shift_with_null_minimum_working_hours()
    {
        $response = $this->postJson('/api/v1/admin/shift', [
            'name' => 'Optional Hours Shift',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'minimum_working_hours' => null,
            'is_night_shift' => 0
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shifts', [
            'shift_name' => 'Optional Hours Shift',
            'minimum_working_hours' => null
        ]);
    }

    public function test_can_create_shift_with_null_is_night_shift()
    {
        $response = $this->postJson('/api/v1/admin/shift', [
            'name' => 'Optional Night Shift',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => null
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('shifts', [
            'shift_name' => 'Optional Night Shift',
            'is_night_shift' => null
        ]);
    }

    public function test_cannot_create_shift_with_same_start_time()
    {
        // Create an existing shift: 08:00 to 16:00
        Shift::create([
            'shift_name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Attempt to create a shift: 08:00 to 12:00 (same start time)
        $response = $this->postJson('/api/v1/admin/shift', [
            'name' => 'Early Shift',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'minimum_working_hours' => 4.00,
            'is_night_shift' => 0
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Shift timings overlap with an existing shift.'
        ]);
    }

    public function test_cannot_create_overlapping_shift_with_different_start_time()
    {
        // Create a night shift: 22:00 to 06:00
        Shift::create([
            'shift_name' => 'Night Shift',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 1,
            'is_active' => 1
        ]);

        // Attempt to create a shift: 05:00 to 13:00 (overlaps from 05:00 to 06:00, but different start time)
        $response = $this->postJson('/api/v1/admin/shift', [
            'name' => 'Early Morning Shift',
            'start_time' => '05:00',
            'end_time' => '13:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Shift timings overlap with an existing shift.'
        ]);
    }

    public function test_can_update_shift_without_overlap()
    {
        $shift = Shift::create([
            'shift_name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Update with same timings should be allowed
        $response = $this->putJson("/api/v1/admin/shift/{$shift->id}", [
            'name' => 'Morning Shift Updated',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0
        ]);

        $response->assertStatus(200);
    }

    public function test_cannot_update_shift_to_same_start_time_as_another()
    {
        Shift::create([
            'shift_name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        $shift2 = Shift::create([
            'shift_name' => 'Evening Shift',
            'start_time' => '16:00:00',
            'end_time' => '00:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Update shift 2 to same start time as shift 1 (08:00)
        $response = $this->putJson("/api/v1/admin/shift/{$shift2->id}", [
            'name' => 'Evening Shift Updated',
            'start_time' => '08:00',
            'end_time' => '23:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Shift timings overlap with an existing shift.'
        ]);
    }

    public function test_cannot_create_shift_with_existing_name()
    {
        Shift::create([
            'shift_name' => 'Unique Shift Name',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        $response = $this->postJson('/api/v1/admin/shift', [
            'name' => 'Unique Shift Name',
            'start_time' => '16:00',
            'end_time' => '00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_cannot_update_shift_to_existing_name()
    {
        $shift1 = Shift::create([
            'shift_name' => 'First Shift Name',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        $shift2 = Shift::create([
            'shift_name' => 'Second Shift Name',
            'start_time' => '16:00:00',
            'end_time' => '00:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        $response = $this->putJson("/api/v1/admin/shift/{$shift2->id}", [
            'name' => 'First Shift Name',
            'start_time' => '16:00',
            'end_time' => '00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['name']]);
    }

    public function test_cannot_update_shift_to_overlapping_timings_with_another()
    {
        Shift::create([
            'shift_name' => 'Night Shift',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 1,
            'is_active' => 1
        ]);

        $shift2 = Shift::create([
            'shift_name' => 'Evening Shift',
            'start_time' => '16:00:00',
            'end_time' => '22:00:00',
            'minimum_working_hours' => 6.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        // Update shift 2 to overlap with shift 1 (e.g. 16:00 to 23:00, overlaps from 22:00 to 23:00)
        $response = $this->putJson("/api/v1/admin/shift/{$shift2->id}", [
            'name' => 'Evening Shift Updated',
            'start_time' => '16:00',
            'end_time' => '23:00',
            'minimum_working_hours' => 7.00,
            'is_night_shift' => 0
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Shift timings overlap with an existing shift.'
        ]);
    }
}
