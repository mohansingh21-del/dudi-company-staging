<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HolidayValidationTest extends TestCase
{
    use RefreshDatabase;

    protected $site;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1,
        ]);

        $admin = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
        ]);
        $admin->roles()->attach($role);

        Sanctum::actingAs($admin);

        $this->site = Site::create(['site_name' => 'Alpha', 'is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'holiday_name' => 'Independence Day',
            'holiday_date' => '2026-08-15',
            'holiday_type' => 'national',
            'site_id' => $this->site->id,
        ], $overrides);
    }

    public function test_duplicate_site_and_date_is_rejected_on_create()
    {
        $this->postJson('/api/v1/admin/holiday', $this->payload())->assertStatus(200);

        $this->postJson('/api/v1/admin/holiday', $this->payload(['holiday_name' => 'Same day again']))
            ->assertStatus(422)
            ->assertJsonPath('errors.holiday_date.0', 'A holiday already exists for this site on this date.');

        $this->assertSame(1, Holiday::active()->count());
    }

    public function test_duplicate_is_rejected_when_date_arrives_in_another_format()
    {
        $this->postJson('/api/v1/admin/holiday', $this->payload())->assertStatus(200);

        $this->postJson('/api/v1/admin/holiday', $this->payload(['holiday_date' => '15-08-2026']))
            ->assertStatus(422)
            ->assertJsonPath('errors.holiday_date.0', 'A holiday already exists for this site on this date.');
    }

    public function test_inactive_duplicate_does_not_block_a_new_holiday()
    {
        Holiday::create($this->payload(['holiday_name' => 'Archived dupe', 'is_active' => 0]));

        $this->postJson('/api/v1/admin/holiday', $this->payload())->assertStatus(200);
    }

    public function test_same_date_on_a_different_site_is_allowed()
    {
        $other = Site::create(['site_name' => 'Beta', 'is_active' => true]);

        $this->postJson('/api/v1/admin/holiday', $this->payload())->assertStatus(200);
        $this->postJson('/api/v1/admin/holiday', $this->payload(['site_id' => $other->id]))->assertStatus(200);

        $this->assertSame(2, Holiday::active()->count());
    }

    public function test_general_holiday_blocks_a_site_entry_on_the_same_date()
    {
        $this->postJson('/api/v1/admin/holiday', $this->payload(['site_id' => null]))->assertStatus(200);

        $this->postJson('/api/v1/admin/holiday', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.holiday_date.0',
                'This date is already a general holiday for all sites, so a separate entry for this site is not required.'
            );
    }

    public function test_duplicate_general_holidays_are_rejected()
    {
        $this->postJson('/api/v1/admin/holiday', $this->payload(['site_id' => null]))->assertStatus(200);
        $this->postJson('/api/v1/admin/holiday', $this->payload(['site_id' => null]))->assertStatus(422);
    }

    public function test_short_and_nameless_holidays_are_rejected()
    {
        $this->postJson('/api/v1/admin/holiday', $this->payload(['holiday_name' => 'ab']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('holiday_name');

        $this->postJson('/api/v1/admin/holiday', $this->payload(['holiday_name' => '123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('holiday_name');
    }

    public function test_missing_date_is_rejected_instead_of_hitting_the_database()
    {
        $this->postJson('/api/v1/admin/holiday', $this->payload(['holiday_date' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('holiday_date');
    }

    public function test_update_does_not_clash_with_itself()
    {
        $holiday = Holiday::create($this->payload());

        $this->putJson("/api/v1/admin/holiday/{$holiday->id}", $this->payload(['holiday_type' => 'public']))
            ->assertStatus(200);
    }

    public function test_update_onto_another_rows_date_is_rejected()
    {
        Holiday::create($this->payload());
        $second = Holiday::create($this->payload(['holiday_name' => 'Local Festival', 'holiday_date' => '2026-08-20']));

        $this->putJson("/api/v1/admin/holiday/{$second->id}", $this->payload(['holiday_name' => 'Local Festival']))
            ->assertStatus(422)
            ->assertJsonPath('errors.holiday_date.0', 'A holiday already exists for this site on this date.');
    }

    public function test_reactivating_a_duplicate_is_refused_with_a_message()
    {
        $live = Holiday::create($this->payload());
        $archived = Holiday::create($this->payload(['holiday_name' => 'Archived dupe', 'is_active' => 0]));

        $this->patchJson("/api/v1/admin/holiday/{$archived->id}/status", ['status' => 1])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A holiday already exists for this site on this date.');

        $this->assertSame(0, (int) $archived->fresh()->is_active);
        $this->assertSame(1, (int) $live->fresh()->is_active);
    }
}
