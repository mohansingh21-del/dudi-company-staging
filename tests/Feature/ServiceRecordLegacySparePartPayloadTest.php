<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceRecord;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The spare part payload the live form posts.
 *
 * It still sends the pre-migration fields — source, store_product_id — beside
 * inventory_id, and repeats one inventory_id across lines while store_product_id
 * varies with the product actually picked. The line is resolved from the product
 * and the record's store, so two products from one store save as two parts.
 */
class ServiceRecordLegacySparePartPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected $machine;
    protected $store;
    protected $otherStore;
    protected $wrench;
    protected $hammer;
    protected $wrenchStock;
    protected $hammerStock;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => bcrypt('password'), 'is_active' => 1]);
        $admin->roles()->attach($role);
        Sanctum::actingAs($admin);

        $equipment = Equipment::create(['name' => 'EXCAVATOR', 'is_active' => 1]);
        $this->machine = EquipmentName::create([
            'equipment_id'   => $equipment->id,
            'equipment_name' => 'EQUPIMENT001',
            'is_active'      => 1,
        ]);

        $category = Category::create(['name' => 'Tools']);
        $sub = SubCategory::create(['category_id' => $category->id, 'name' => 'Hand Tools']);

        $this->wrench = Product::create(['sub_category_id' => $sub->id, 'name' => 'Wrench Sets', 'min_stock' => 0, 'is_active' => 1]);
        $this->hammer = Product::create(['sub_category_id' => $sub->id, 'name' => 'Hammer tool', 'min_stock' => 0, 'is_active' => 1]);

        $this->store = Store::create(['name' => 'APexindustrial Spares', 'is_active' => 1]);
        $this->otherStore = Store::create(['name' => 'XYZ Spares', 'is_active' => 1]);

        $this->wrenchStock = Inventory::create([
            'store_id' => $this->store->id, 'product_id' => $this->wrench->id,
            'quantity' => 308, 'left_quantity' => 75, 'is_active' => 1,
        ]);
        $this->hammerStock = Inventory::create([
            'store_id' => $this->store->id, 'product_id' => $this->hammer->id,
            'quantity' => 100, 'left_quantity' => 40, 'is_active' => 1,
        ]);
    }

    public function test_two_products_of_one_store_save_as_two_parts()
    {
        // Both lines carry the wrench's inventory_id; only store_product_id
        // says which product each line is really for.
        $id = $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['store_product_id' => $this->wrench->id, 'inventory_id' => $this->wrenchStock->id, 'part_name' => 'Wrench Sets'],
            ['store_product_id' => $this->hammer->id, 'inventory_id' => $this->wrenchStock->id, 'part_name' => 'Hammer tool'],
        ]))->assertStatus(201)
            ->assertJsonPath('data.spare_parts_amount_total', '200.00')
            ->json('data.id');

        $this->assertDatabaseHas('service_spare_parts', [
            'service_record_id' => $id,
            'inventory_id'      => $this->wrenchStock->id,
            'part_name'         => 'Wrench Sets',
        ]);

        $this->assertDatabaseHas('service_spare_parts', [
            'service_record_id' => $id,
            'inventory_id'      => $this->hammerStock->id,
            'part_name'         => 'Hammer tool',
        ]);

        // One unit off each balance, not two off the wrench.
        $this->assertSame('74.00', $this->wrenchStock->fresh()->left_quantity);
        $this->assertSame('39.00', $this->hammerStock->fresh()->left_quantity);
    }

    public function test_an_update_resolves_the_same_payload_and_keeps_stock_straight()
    {
        $id = $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['store_product_id' => $this->wrench->id, 'inventory_id' => $this->wrenchStock->id, 'part_name' => 'Wrench Sets'],
        ]))->assertStatus(201)->json('data.id');

        $this->putJson('/api/v1/admin/service-records/' . $id, [
            'store_id'            => $this->store->id,
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['source' => 'store', 'store_product_id' => $this->wrench->id, 'inventory_id' => $this->wrenchStock->id, 'quantity' => 1, 'amount' => 100],
                ['source' => 'store', 'store_product_id' => $this->hammer->id, 'inventory_id' => $this->wrenchStock->id, 'quantity' => 3, 'amount' => 300],
            ],
        ])->assertStatus(200);

        $this->assertDatabaseHas('service_spare_parts', [
            'service_record_id' => $id,
            'inventory_id'      => $this->hammerStock->id,
            'quantity'          => 3.00,
        ]);

        $this->assertSame('74.00', $this->wrenchStock->fresh()->left_quantity);
        $this->assertSame('37.00', $this->hammerStock->fresh()->left_quantity);
    }

    public function test_an_update_without_store_id_falls_back_to_the_stored_store()
    {
        $id = $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['store_product_id' => $this->wrench->id, 'inventory_id' => $this->wrenchStock->id, 'part_name' => 'Wrench Sets'],
        ]))->assertStatus(201)->json('data.id');

        $this->putJson('/api/v1/admin/service-records/' . $id, [
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['source' => 'store', 'store_product_id' => $this->hammer->id, 'inventory_id' => $this->wrenchStock->id, 'quantity' => 2, 'amount' => 200],
            ],
        ])->assertStatus(200);

        $this->assertDatabaseHas('service_spare_parts', [
            'service_record_id' => $id,
            'inventory_id'      => $this->hammerStock->id,
            'quantity'          => 2.00,
        ]);

        // The wrench issued on create came back when it left the record.
        $this->assertSame('75.00', $this->wrenchStock->fresh()->left_quantity);
        $this->assertSame('38.00', $this->hammerStock->fresh()->left_quantity);
    }

    public function test_a_product_the_store_does_not_stock_is_rejected()
    {
        $elsewhere = Inventory::create([
            'store_id' => $this->otherStore->id, 'product_id' => $this->wrench->id,
            'quantity' => 10, 'left_quantity' => 10, 'is_active' => 1,
        ]);

        $unstocked = Product::create([
            'sub_category_id' => $this->wrench->sub_category_id,
            'name' => 'Torque Gauge', 'min_stock' => 0, 'is_active' => 1,
        ]);

        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['store_product_id' => $unstocked->id, 'inventory_id' => $this->wrenchStock->id, 'part_name' => 'Torque Gauge'],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('spare_parts.0.store_product_id');

        $this->assertSame(0, ServiceRecord::count());
        $this->assertSame('75.00', $this->wrenchStock->fresh()->left_quantity);
        $this->assertSame('10.00', $elsewhere->fresh()->left_quantity);
    }

    public function test_the_same_part_on_two_lines_is_rejected()
    {
        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['store_product_id' => $this->wrench->id, 'inventory_id' => $this->wrenchStock->id, 'part_name' => 'Wrench Sets'],
            ['store_product_id' => $this->wrench->id, 'inventory_id' => $this->wrenchStock->id, 'part_name' => 'Wrench Sets'],
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('spare_parts.1.inventory_id');

        $this->assertSame(0, ServiceRecord::count());
        $this->assertSame('75.00', $this->wrenchStock->fresh()->left_quantity);
    }

    public function test_a_payload_sending_only_inventory_id_still_works()
    {
        $id = $this->postJson('/api/v1/admin/service-records', [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-09-12',
            'base_service_amount'  => 147,
            'store_id'             => $this->store->id,
            'job_card_number'      => '1122',
            'spare_parts_changed'  => true,
            'spare_parts'          => [
                ['inventory_id' => $this->wrenchStock->id, 'quantity' => 1, 'amount' => 100],
                ['inventory_id' => $this->hammerStock->id, 'quantity' => 1, 'amount' => 100],
            ],
        ])->assertStatus(201)->json('data.id');

        $this->assertDatabaseHas('service_spare_parts', ['service_record_id' => $id, 'inventory_id' => $this->wrenchStock->id]);
        $this->assertDatabaseHas('service_spare_parts', ['service_record_id' => $id, 'inventory_id' => $this->hammerStock->id]);
    }

    public function test_product_id_from_the_show_response_resolves_like_store_product_id()
    {
        // What an edit form gets back from GET: product_id beside the same
        // repeated inventory_id. The product id decides the stock row.
        $id = $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['product_id' => $this->wrench->id, 'inventory_id' => $this->wrenchStock->id],
            ['product_id' => $this->hammer->id, 'inventory_id' => $this->wrenchStock->id],
        ]))->assertStatus(201)->json('data.id');

        $this->getJson('/api/v1/admin/service-records/' . $id)
            ->assertStatus(200)
            ->assertJsonPath('data.spare_parts.0.product_id', $this->wrench->id)
            ->assertJsonPath('data.spare_parts.1.product_id', $this->hammer->id);

        $this->assertSame('74.00', $this->wrenchStock->fresh()->left_quantity);
        $this->assertSame('39.00', $this->hammerStock->fresh()->left_quantity);
    }

    /**
     * The live form's payload, minus the fields that do not vary per line.
     *
     * @param  array  $spareParts
     * @return array
     */
    protected function payload(array $spareParts)
    {
        return [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-09-12',
            'base_service_amount'  => 147,
            'store_id'             => $this->store->id,
            'job_card_number'      => '1122',
            'spare_parts_changed'  => true,
            'spare_parts'          => array_map(function ($part) {
                return $part + ['source' => 'store', 'vendor_name' => null, 'quantity' => 1, 'amount' => 100];
            }, $spareParts),
        ];
    }
}
