<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentModelNumber;
use App\Models\EquipmentName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EquipmentModelNumberTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $workerUser;
    protected $dumper;
    protected $dozer;
    protected $prima;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);
        $workerRole = Role::create(['name' => 'Worker', 'slug' => 'worker', 'is_active' => 1]);

        $this->adminUser = User::create(['email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->adminUser->roles()->attach($adminRole);

        $this->workerUser = User::create(['email' => 'worker@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $this->workerUser->roles()->attach($workerRole);

        $this->dumper = Equipment::create(['name' => 'Dumper', 'is_active' => 1]);
        $this->dozer = Equipment::create(['name' => 'Dozer', 'is_active' => 1]);

        $this->prima = EquipmentModelNumber::create([
            'equipment_id' => $this->dumper->id,
            'model_number' => 'PRIMA 2830.K',
            'manufacturer' => 'Tata Motors',
            'is_active' => 1,
        ]);

        Sanctum::actingAs($this->adminUser);
    }

    protected function machinePayload(array $overrides = [])
    {
        return array_merge([
            'equipment_id' => $this->dumper->id,
            'equipment_model_number_id' => $this->prima->id,
            'equipment_name' => 'DMP-101',
            'chassis_number' => 'MAT448123P5K00001',
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // Model number master
    // ---------------------------------------------------------------

    public function test_admin_can_create_model_number()
    {
        $response = $this->postJson('/api/v1/admin/equipment-model-numbers', [
            'equipment_id' => $this->dumper->id,
            'model_number' => 'PRO 6028T',
            'manufacturer' => 'Eicher',
        ]);

        $response->assertStatus(200)->assertJson(['status' => 200]);
        $this->assertDatabaseHas('equipment_model_numbers', [
            'equipment_id' => $this->dumper->id,
            'model_number' => 'PRO 6028T',
            'is_active' => 1,
        ]);
    }

    public function test_model_number_is_unique_within_category_only()
    {
        $this->postJson('/api/v1/admin/equipment-model-numbers', [
            'equipment_id' => $this->dumper->id,
            'model_number' => 'PRIMA 2830.K',
        ])->assertStatus(422)->assertJsonValidationErrors('model_number');

        $this->postJson('/api/v1/admin/equipment-model-numbers', [
            'equipment_id' => $this->dozer->id,
            'model_number' => 'PRIMA 2830.K',
        ])->assertStatus(200);
    }

    public function test_model_number_requires_category_and_number()
    {
        $this->postJson('/api/v1/admin/equipment-model-numbers', ['manufacturer' => 'Tata Motors'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['equipment_id', 'model_number']);
    }

    public function test_admin_can_list_show_update_and_toggle_model_number()
    {
        $this->getJson('/api/v1/admin/equipment-model-numbers?search=PRIMA')
            ->assertStatus(200)
            ->assertJsonPath('data.0.model_number', 'PRIMA 2830.K')
            ->assertJsonPath('pagination.total', 1);

        $this->getJson("/api/v1/admin/equipment-model-numbers/{$this->prima->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.equipment_category_name', 'Dumper');

        $this->putJson("/api/v1/admin/equipment-model-numbers/{$this->prima->id}", [
            'equipment_id' => $this->dumper->id,
            'model_number' => 'PRIMA 2830.K BS6',
            'manufacturer' => 'Tata Motors',
        ])->assertStatus(200);
        $this->assertSame('PRIMA 2830.K BS6', $this->prima->fresh()->model_number);

        $this->patchJson("/api/v1/admin/equipment-model-numbers/{$this->prima->id}/status", ['status' => 0])
            ->assertStatus(200);
        $this->assertSame(0, $this->prima->fresh()->is_active);
    }

    public function test_model_in_use_cannot_be_deleted_or_moved_to_other_category()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload())->assertStatus(200);

        $this->deleteJson("/api/v1/admin/equipment-model-numbers/{$this->prima->id}")
            ->assertStatus(422);

        $this->putJson("/api/v1/admin/equipment-model-numbers/{$this->prima->id}", [
            'equipment_id' => $this->dozer->id,
            'model_number' => 'PRIMA 2830.K',
        ])->assertStatus(422);

        $this->assertDatabaseHas('equipment_model_numbers', [
            'id' => $this->prima->id,
            'equipment_id' => $this->dumper->id,
        ]);
    }

    public function test_unused_model_can_be_deleted()
    {
        $this->deleteJson("/api/v1/admin/equipment-model-numbers/{$this->prima->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('equipment_model_numbers', ['id' => $this->prima->id]);
    }

    public function test_non_admin_cannot_manage_model_numbers()
    {
        Sanctum::actingAs($this->workerUser);

        $this->postJson('/api/v1/admin/equipment-model-numbers', [
            'equipment_id' => $this->dumper->id,
            'model_number' => 'PRO 6028T',
        ])->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // Dropdown
    // ---------------------------------------------------------------

    public function test_dropdown_lists_only_active_models_of_selected_category()
    {
        EquipmentModelNumber::create(['equipment_id' => $this->dumper->id, 'model_number' => 'OLD 1', 'is_active' => 0]);
        EquipmentModelNumber::create(['equipment_id' => $this->dozer->id, 'model_number' => 'D85', 'is_active' => 1]);

        $response = $this->getJson("/api/v1/equipment-model-numbers?equipment_id={$this->dumper->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.model_number', 'PRIMA 2830.K');
    }

    public function test_dropdown_is_available_to_any_logged_in_user()
    {
        Sanctum::actingAs($this->workerUser);

        $this->getJson('/api/v1/equipment-model-numbers')->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // Equipment names with a model
    // ---------------------------------------------------------------

    public function test_machine_is_created_with_model_and_returns_model_number()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload())->assertStatus(200);

        $machine = EquipmentName::where('equipment_name', 'DMP-101')->firstOrFail();
        $this->assertSame($this->prima->id, (int) $machine->equipment_model_number_id);

        $data = $this->getJson("/api/v1/admin/equipment-names/{$machine->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.model_number', 'PRIMA 2830.K')
            ->json('data');
        $this->assertArrayNotHasKey('equipment_code', $data);
    }

    public function test_many_machines_can_share_one_model()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload())->assertStatus(200);
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload([
            'equipment_name' => 'DMP-102 Dumper',
            'chassis_number' => 'MAT448123P5K00002',
        ]))->assertStatus(200);

        $this->assertSame(2, EquipmentName::where('equipment_model_number_id', $this->prima->id)->count());
    }

    public function test_machine_name_must_be_unique_ignoring_case_and_spaces()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload())->assertStatus(200);

        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload([
            'equipment_name' => ' dmp-101 ',
            'chassis_number' => 'MAT448123P5K00003',
        ]))->assertStatus(422)->assertJsonValidationErrors('equipment_name');
    }

    public function test_chassis_number_must_be_unique()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload())->assertStatus(200);

        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload([
            'equipment_name' => 'DMP-103',
        ]))->assertStatus(422)->assertJsonValidationErrors('chassis_number');
    }

    public function test_machines_without_chassis_are_allowed()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload([
            'equipment_name' => 'PUMP-01', 'chassis_number' => null,
        ]))->assertStatus(200);

        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload([
            'equipment_name' => 'PUMP-02', 'chassis_number' => '',
        ]))->assertStatus(200);
    }

    public function test_model_is_required()
    {
        $payload = $this->machinePayload();
        unset($payload['equipment_model_number_id']);

        $this->postJson('/api/v1/admin/equipment-names', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('equipment_model_number_id');
    }

    public function test_model_must_belong_to_selected_category()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload([
            'equipment_id' => $this->dozer->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('equipment_model_number_id');
    }

    public function test_inactive_model_cannot_be_assigned()
    {
        $this->prima->update(['is_active' => 0]);

        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload())
            ->assertStatus(422)->assertJsonValidationErrors('equipment_model_number_id');
    }

    public function test_machine_keeps_its_model_after_the_model_is_deactivated()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload())->assertStatus(200);
        $machine = EquipmentName::where('equipment_name', 'DMP-101')->firstOrFail();

        $this->prima->update(['is_active' => 0]);

        $this->putJson("/api/v1/admin/equipment-names/{$machine->id}", $this->machinePayload([
            'equipment_name' => 'DMP-101 Tata',
        ]))->assertStatus(200);

        $this->assertSame('DMP-101 Tata', $machine->fresh()->equipment_name);
    }

    public function test_update_can_keep_own_name_and_chassis_and_requires_model()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload())->assertStatus(200);
        $machine = EquipmentName::where('equipment_name', 'DMP-101')->firstOrFail();

        $this->putJson("/api/v1/admin/equipment-names/{$machine->id}", $this->machinePayload())
            ->assertStatus(200);

        $payload = $this->machinePayload();
        unset($payload['equipment_model_number_id']);
        $this->putJson("/api/v1/admin/equipment-names/{$machine->id}", $payload)
            ->assertStatus(422)->assertJsonValidationErrors('equipment_model_number_id');
    }

    public function test_equipment_code_is_ignored()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload([
            'equipment_code' => 'SHOULD-NOT-SAVE',
        ]))->assertStatus(200);

        $this->assertNull(EquipmentName::where('equipment_name', 'DMP-101')->value('equipment_code'));
    }

    public function test_machine_lists_search_and_return_model_number()
    {
        $this->postJson('/api/v1/admin/equipment-names', $this->machinePayload())->assertStatus(200);

        $this->getJson('/api/v1/admin/equipment-names?search=PRIMA')
            ->assertStatus(200)
            ->assertJsonPath('data.0.equipment_name', 'DMP-101')
            ->assertJsonPath('data.0.model_number', 'PRIMA 2830.K');

        $this->getJson("/api/v1/machine-names/{$this->dumper->id}?search=PRIMA")
            ->assertStatus(200)
            ->assertJsonPath('data.0.model_number', 'PRIMA 2830.K');

        $this->getJson('/api/v1/active-machines?search=PRIMA')
            ->assertStatus(200)
            ->assertJsonPath('data.0.model_number', 'PRIMA 2830.K');
    }
}
