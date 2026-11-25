<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HolidayStatusToggleTest extends TestCase
{
    use RefreshDatabase;

    protected $site;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $admin = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $admin->roles()->attach($role);

        Sanctum::actingAs($admin);

        $this->site = Site::create(['site_name' => 'Site A', 'is_active' => 1]);
    }

    private function makeHoliday(string $date, int $isActive = 1): Holiday
    {
        return Holiday::create([
            'holiday_name' => 'Test Holiday',
            'holiday_date' => $date,
            'holiday_type' => 'National',
            'site_id' => $this->site->id,
            'is_active' => $isActive,
        ]);
    }

    public function test_past_holiday_cannot_be_deactivated()
    {
        $holiday = $this->makeHoliday(now()->subDay()->toDateString());

        $this->patchJson("/api/v1/admin/holiday/{$holiday->id}/status", ['status' => 0])
            ->assertStatus(422)
            ->assertJson(['message' => 'Past date holiday cannot be deactivated']);

        $this->assertEquals(1, $holiday->fresh()->is_active);
    }

    public function test_past_holiday_can_be_activated()
    {
        $holiday = $this->makeHoliday(now()->subDay()->toDateString(), 0);

        $this->patchJson("/api/v1/admin/holiday/{$holiday->id}/status", ['status' => 1])
            ->assertStatus(200);

        $this->assertEquals(1, $holiday->fresh()->is_active);
    }

    public function test_today_holiday_can_be_deactivated()
    {
        $holiday = $this->makeHoliday(now()->toDateString());

        $this->patchJson("/api/v1/admin/holiday/{$holiday->id}/status", ['status' => 0])
            ->assertStatus(200);

        $this->assertEquals(0, $holiday->fresh()->is_active);
    }

    public function test_future_holiday_can_be_deactivated()
    {
        $holiday = $this->makeHoliday(now()->addDays(5)->toDateString());

        $this->patchJson("/api/v1/admin/holiday/{$holiday->id}/status", ['status' => 0])
            ->assertStatus(200);

        $this->assertEquals(0, $holiday->fresh()->is_active);
    }
}
