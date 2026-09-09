<?php

namespace Tests\Feature;

use App\Mail\LowStockAlertMail;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceRecord;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Spare parts drawn from an outside store: the second inventory's half of the
 * service-record flow.
 */
class ServiceRecordStoreSparePartTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $machine;
    protected $product;
    protected $inventory;
    protected $store;
    protected $storeProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name'      => 'System-Administrator',
            'slug'      => 'super-admin',
            'is_active' => 1
        ]);

        $this->adminUser = User::create([
            'name'      => 'Admin User',
            'email'     => 'admin@test.com',
            'password'  => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($role);

        Sanctum::actingAs($this->adminUser);

        $equipment = Equipment::create(['name' => 'Excavator', 'is_active' => 1]);
        $this->machine = EquipmentName::create([
            'equipment_id'   => $equipment->id,
            'equipment_name' => 'EXC-001',
            'is_active'      => 1
        ]);

        $category = Category::create(['name' => 'Spare Parts']);
        $subCategory = SubCategory::create(['category_id' => $category->id, 'name' => 'Filters']);
        $this->product = Product::create([
            'sub_category_id' => $subCategory->id,
            'name'            => 'Oil Filter XP-90',
            'min_stock'       => 5,
            'is_active'       => 1
        ]);

        $this->inventory = Inventory::create([
            'product_id'    => $this->product->id,
            'quantity'      => 50.00,
            'left_quantity' => 50.00,
        ]);

        $this->store = Store::create(['name' => 'ABC Traders', 'is_active' => 1]);
        $this->storeProduct = StoreProduct::create([
            'store_id'      => $this->store->id,
            'product_id'    => $this->product->id,
            'quantity'      => 20.00,
            'left_quantity' => 20.00,
            'threshold'     => 4.00,
            'is_active'     => 1,
        ]);
    }

    public function test_store_part_deducts_store_stock_and_leaves_own_inventory_alone()
    {
        $response = $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['source' => 'store', 'store_product_id' => $this->storeProduct->id, 'quantity' => 3, 'unit_price' => 100],
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.spare_parts_amount_total', '300.00')
            ->assertJsonPath('data.total_amount', '300.00')
            ->assertJsonPath('data.job_card_number', 'JC-2026-0001')
            ->assertJsonPath('data.store_id', $this->store->id);

        $this->assertSame('17.00', $this->storeProduct->fresh()->left_quantity);
        // The same product in the mine's own inventory is untouched.
        $this->assertSame('50.00', $this->inventory->fresh()->left_quantity);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'store_id'   => $this->store->id,
            'type'       => 'out',
            'action'     => 'service_spare_part',
            'quantity'   => -3.00,
        ]);

        $this->assertDatabaseHas('service_spare_parts', [
            'source'               => 'store',
            'store_product_id'     => $this->storeProduct->id,
            'inventory_product_id' => null,
            'part_name'            => 'Oil Filter XP-90',
            'amount'               => 300.00,
        ]);
    }

    public function test_both_inventories_can_be_drawn_from_on_one_record()
    {
        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['source' => 'inventory', 'inventory_product_id' => $this->product->id, 'quantity' => 2],
            ['source' => 'store', 'store_product_id' => $this->storeProduct->id, 'quantity' => 3, 'unit_price' => 100],
        ]))->assertStatus(201);

        $this->assertSame('48.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame('17.00', $this->storeProduct->fresh()->left_quantity);
    }

    public function test_threshold_is_a_hard_floor_for_store_stock()
    {
        Mail::fake();

        // 20 on hand, floor of 4: issuing 17 would land at 3.
        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['source' => 'store', 'store_product_id' => $this->storeProduct->id, 'quantity' => 17, 'unit_price' => 10],
        ]))->assertStatus(422)->assertJsonValidationErrors('spare_parts');

        $this->assertSame('20.00', $this->storeProduct->fresh()->left_quantity);
        $this->assertSame(0, ServiceRecord::count());

        Mail::assertSent(LowStockAlertMail::class);
    }

    public function test_issuing_down_to_the_threshold_is_allowed_and_alerts()
    {
        Mail::fake();

        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['source' => 'store', 'store_product_id' => $this->storeProduct->id, 'quantity' => 16, 'unit_price' => 10],
        ]))->assertStatus(201);

        $this->assertSame('4.00', $this->storeProduct->fresh()->left_quantity);

        Mail::assertSent(LowStockAlertMail::class, function ($mail) {
            return $mail->storeName === 'ABC Traders';
        });
    }

    public function test_a_part_from_another_store_is_rejected()
    {
        $otherStore = Store::create(['name' => 'XYZ Spares', 'is_active' => 1]);
        $otherStoreProduct = StoreProduct::create([
            'store_id'      => $otherStore->id,
            'product_id'    => $this->product->id,
            'quantity'      => 10,
            'left_quantity' => 10,
            'threshold'     => 1,
            'is_active'     => 1,
        ]);

        // store_id on the record says ABC Traders; the part belongs to XYZ.
        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['source' => 'store', 'store_product_id' => $otherStoreProduct->id, 'quantity' => 1, 'unit_price' => 10],
        ]))->assertStatus(422)->assertJsonValidationErrors('spare_parts');

        $this->assertSame('10.00', $otherStoreProduct->fresh()->left_quantity);
        $this->assertSame(0, ServiceRecord::count());
    }

    public function test_store_id_and_job_card_number_are_required_for_a_store_part()
    {
        $payload = $this->payload([
            ['source' => 'store', 'store_product_id' => $this->storeProduct->id, 'quantity' => 1, 'unit_price' => 10],
        ]);
        unset($payload['store_id'], $payload['job_card_number']);

        $this->postJson('/api/v1/admin/service-records', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['store_id', 'job_card_number']);

        $this->assertSame('20.00', $this->storeProduct->fresh()->left_quantity);
    }

    public function test_an_inventory_only_record_needs_neither_store_nor_job_card()
    {
        $payload = $this->payload([
            ['source' => 'inventory', 'inventory_product_id' => $this->product->id, 'quantity' => 2],
        ]);
        unset($payload['store_id'], $payload['job_card_number']);

        $this->postJson('/api/v1/admin/service-records', $payload)->assertStatus(201);

        $this->assertSame('48.00', $this->inventory->fresh()->left_quantity);
    }

    public function test_store_product_id_must_exist()
    {
        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['source' => 'store', 'store_product_id' => 9999, 'quantity' => 1, 'unit_price' => 10],
        ]))->assertStatus(422)->assertJsonValidationErrors('spare_parts.0.store_product_id');
    }

    public function test_raising_a_store_part_quantity_deducts_only_the_difference()
    {
        $id = $this->createRecordWithStorePart(3);

        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'store_id'            => $this->store->id,
            'job_card_number'     => 'JC-2026-0001',
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['source' => 'store', 'store_product_id' => $this->storeProduct->id, 'quantity' => 5, 'unit_price' => 100],
            ],
        ])->assertStatus(200);

        // 20 - 5, not 20 - 3 - 5.
        $this->assertSame('15.00', $this->storeProduct->fresh()->left_quantity);
        $this->assertSame('500.00', ServiceRecord::find($id)->spare_parts_amount_total);
    }

    public function test_lowering_a_store_part_quantity_returns_the_difference()
    {
        $id = $this->createRecordWithStorePart(5);
        $this->assertSame('15.00', $this->storeProduct->fresh()->left_quantity);

        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'store_id'            => $this->store->id,
            'job_card_number'     => 'JC-2026-0001',
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['source' => 'store', 'store_product_id' => $this->storeProduct->id, 'quantity' => 2, 'unit_price' => 100],
            ],
        ])->assertStatus(200);

        $this->assertSame('18.00', $this->storeProduct->fresh()->left_quantity);
        $this->assertSame('200.00', ServiceRecord::find($id)->spare_parts_amount_total);
    }

    public function test_removing_a_store_part_returns_all_of_it()
    {
        $id = $this->createRecordWithStorePart(5);

        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'spare_parts_changed' => false,
        ])->assertStatus(200);

        $this->assertSame('20.00', $this->storeProduct->fresh()->left_quantity);
        $this->assertSame('0.00', ServiceRecord::find($id)->spare_parts_amount_total);
        $this->assertSame(0, \DB::table('service_spare_parts')->where('service_record_id', $id)->count());
    }

    public function test_editing_a_record_preserves_store_part_prices()
    {
        $id = $this->createRecordWithStorePart(3);
        $this->assertSame('300.00', ServiceRecord::find($id)->spare_parts_amount_total);

        // Re-post the same list untouched: the money must survive the round trip.
        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'store_id'            => $this->store->id,
            'job_card_number'     => 'JC-2026-0001',
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['source' => 'store', 'store_product_id' => $this->storeProduct->id, 'quantity' => 3, 'unit_price' => 100],
            ],
        ])->assertStatus(200);

        $this->assertSame('300.00', ServiceRecord::find($id)->spare_parts_amount_total);
        $this->assertSame('17.00', $this->storeProduct->fresh()->left_quantity);
    }

    public function test_swapping_store_parts_returns_before_it_deducts()
    {
        $second = Product::create([
            'sub_category_id' => $this->product->sub_category_id,
            'name'            => 'Hydraulic Hose HX-12',
            'min_stock'       => 0,
            'is_active'       => 1,
        ]);
        $secondStoreProduct = StoreProduct::create([
            'store_id'      => $this->store->id,
            'product_id'    => $second->id,
            'quantity'      => 6,
            'left_quantity' => 6,
            'threshold'     => 5,
            'is_active'     => 1,
        ]);

        // Only one unit is issuable above the floor of 5, and it is already out.
        $id = $this->createRecordWithStorePart(1, $secondStoreProduct->id);
        $this->assertSame('5.00', $secondStoreProduct->fresh()->left_quantity);

        // Re-posting the same quantity must not fail: nothing net is issued.
        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'store_id'            => $this->store->id,
            'job_card_number'     => 'JC-2026-0001',
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['source' => 'store', 'store_product_id' => $secondStoreProduct->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ])->assertStatus(200);

        $this->assertSame('5.00', $secondStoreProduct->fresh()->left_quantity);
    }

    public function test_switching_a_part_from_own_inventory_to_the_store()
    {
        $id = $this->createRecordWithInventoryPart(4);
        $this->assertSame('46.00', $this->inventory->fresh()->left_quantity);

        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'store_id'            => $this->store->id,
            'job_card_number'     => 'JC-2026-0001',
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['source' => 'store', 'store_product_id' => $this->storeProduct->id, 'quantity' => 4, 'unit_price' => 100],
            ],
        ])->assertStatus(200);

        // Own stock fully returned, store stock issued — the same product, two
        // separate balances.
        $this->assertSame('50.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame('16.00', $this->storeProduct->fresh()->left_quantity);
        $this->assertSame('400.00', ServiceRecord::find($id)->spare_parts_amount_total);
    }

    /**
     * @param  array  $spareParts
     * @return array
     */
    protected function payload(array $spareParts)
    {
        return [
            'is_breakdown_service' => false,
            'machine_id'           => $this->machine->id,
            'service_date'         => '2026-09-07',
            'base_service_amount'  => 0,
            'store_id'             => $this->store->id,
            'job_card_number'      => 'JC-2026-0001',
            'spare_parts_changed'  => true,
            'spare_parts'          => $spareParts,
        ];
    }

    /**
     * @return int
     */
    protected function createRecordWithStorePart($quantity, $storeProductId = null)
    {
        return $this->postJson('/api/v1/admin/service-records', $this->payload([
            [
                'source'           => 'store',
                'store_product_id' => $storeProductId ?: $this->storeProduct->id,
                'quantity'         => $quantity,
                'unit_price'       => 100,
            ],
        ]))->assertStatus(201)->json('data.id');
    }

    /**
     * @return int
     */
    protected function createRecordWithInventoryPart($quantity)
    {
        return $this->postJson('/api/v1/admin/service-records', $this->payload([
            [
                'source'               => 'inventory',
                'inventory_product_id' => $this->product->id,
                'quantity'             => $quantity,
            ],
        ]))->assertStatus(201)->json('data.id');
    }
}
