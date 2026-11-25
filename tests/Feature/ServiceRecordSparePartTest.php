<?php

namespace Tests\Feature;

use App\Mail\LowStockAlertMail;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\EquipmentName;
use App\Models\Inventory;
use App\Models\InventoryAlert;
use App\Models\Product;
use App\Models\Role;
use App\Models\ServiceRecord;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Spare parts on a service record.
 *
 * Every part comes out of one store's stock, identified by its inventory row.
 * The same product held at two stores is two independent balances, and a record
 * draws from exactly one of them.
 */
class ServiceRecordSparePartTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $machine;
    protected $product;
    protected $store;
    protected $otherStore;
    protected $inventory;
    protected $otherInventory;

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

        // min_stock 5 is the floor at every store that carries this product.
        $this->product = Product::create([
            'sub_category_id' => $subCategory->id,
            'name'            => 'Oil Filter XP-90',
            'min_stock'       => 5,
            'is_active'       => 1
        ]);

        $this->store = Store::create(['name' => 'ABC Traders', 'is_active' => 1]);
        $this->otherStore = Store::create(['name' => 'XYZ Spares', 'is_active' => 1]);

        $this->inventory = Inventory::create([
            'store_id'      => $this->store->id,
            'product_id'    => $this->product->id,
            'quantity'      => 20.00,
            'left_quantity' => 20.00,
            'is_active'     => 1,
        ]);

        // The same product at a second store — a separate balance.
        $this->otherInventory = Inventory::create([
            'store_id'      => $this->otherStore->id,
            'product_id'    => $this->product->id,
            'quantity'      => 50.00,
            'left_quantity' => 50.00,
            'is_active'     => 1,
        ]);
    }

    public function test_a_part_deducts_its_own_store_and_leaves_the_other_alone()
    {
        $response = $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['inventory_id' => $this->inventory->id, 'quantity' => 3, 'amount' => 100],
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.spare_parts_amount_total', '300.00')
            ->assertJsonPath('data.total_amount', '300.00')
            ->assertJsonPath('data.job_card_number', 'JC-2026-0001')
            ->assertJsonPath('data.store_id', $this->store->id);

        $this->assertSame('17.00', $this->inventory->fresh()->left_quantity);
        // The same product at the other store is untouched.
        $this->assertSame('50.00', $this->otherInventory->fresh()->left_quantity);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'store_id'   => $this->store->id,
            'type'       => 'out',
            'action'     => 'service_spare_part',
            'quantity'   => -3.00,
        ]);

        $this->assertDatabaseHas('service_spare_parts', [
            'inventory_id' => $this->inventory->id,
            'part_name'    => 'Oil Filter XP-90',
            'vendor_name'  => 'ABC Traders',
            'amount'       => 300.00,
        ]);
    }

    public function test_amount_is_the_unit_price_and_the_line_is_multiplied_out()
    {
        // The user enters a quantity of 4 at 225 each — a per-unit rate, not
        // the line total. The line amount is worked out from it.
        $id = $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['inventory_id' => $this->inventory->id, 'quantity' => 4, 'amount' => 225],
        ]))->assertStatus(201)
            ->assertJsonPath('data.spare_parts_amount_total', '900.00')
            ->json('data.id');

        $this->assertDatabaseHas('service_spare_parts', [
            'service_record_id' => $id,
            'quantity'          => 4.00,
            'amount'            => 900.00,
            'unit_price'        => 225.00,
        ]);
    }

    public function test_a_part_left_unpriced_costs_nothing()
    {
        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['inventory_id' => $this->inventory->id, 'quantity' => 2],
        ]))->assertStatus(201)
            ->assertJsonPath('data.spare_parts_amount_total', '0.00');

        $this->assertSame('18.00', $this->inventory->fresh()->left_quantity);

        $this->assertDatabaseHas('service_spare_parts', [
            'inventory_id' => $this->inventory->id,
            'quantity'     => 2.00,
            'amount'       => 0.00,
            'unit_price'   => 0.00,
        ]);
    }

    public function test_issuing_below_min_stock_is_allowed_and_raises_one_alert()
    {
        Mail::fake();

        // 20 on hand, min_stock 5: issuing 16 lands at 4 — under the line but
        // still on the shelf, so it goes through.
        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['product_id' => $this->product->id, 'quantity' => 16, 'amount' => 10],
        ]))->assertStatus(201);

        $this->assertSame('4.00', $this->inventory->fresh()->left_quantity);

        Mail::assertSent(LowStockAlertMail::class, 1);

        $alert = InventoryAlert::where('inventory_id', $this->inventory->id)->sole();
        $this->assertSame('low_stock', $alert->type);
        $this->assertSame('service_record', $alert->source);
        $this->assertNull($alert->resolved_at);
    }

    public function test_issuing_more_than_is_left_is_rejected()
    {
        Mail::fake();

        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['product_id' => $this->product->id, 'quantity' => 21, 'amount' => 10],
        ]))->assertStatus(422)->assertJsonValidationErrors('spare_parts');

        $this->assertSame('20.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame(0, ServiceRecord::count());
        $this->assertSame(0, InventoryAlert::count());

        Mail::assertNothingSent();
    }

    public function test_issuing_down_to_the_floor_is_allowed_and_alerts()
    {
        Mail::fake();

        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['inventory_id' => $this->inventory->id, 'quantity' => 15, 'amount' => 10],
        ]))->assertStatus(201);

        $this->assertSame('5.00', $this->inventory->fresh()->left_quantity);

        Mail::assertSent(LowStockAlertMail::class, function ($mail) {
            return $mail->storeName === 'ABC Traders';
        });
    }

    public function test_a_part_from_another_store_is_rejected()
    {
        // store_id on the record says ABC Traders; the part belongs to XYZ.
        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['inventory_id' => $this->otherInventory->id, 'quantity' => 1, 'amount' => 10],
        ]))->assertStatus(422)->assertJsonValidationErrors('store_id');

        $this->assertSame('50.00', $this->otherInventory->fresh()->left_quantity);
        $this->assertSame(0, ServiceRecord::count());
    }

    public function test_parts_from_two_stores_on_one_record_are_rejected()
    {
        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['inventory_id' => $this->inventory->id, 'quantity' => 1, 'amount' => 10],
            ['inventory_id' => $this->otherInventory->id, 'quantity' => 1, 'amount' => 10],
        ]))->assertStatus(422)->assertJsonValidationErrors('spare_parts');

        // Rejected at validation, so nothing moved anywhere.
        $this->assertSame('20.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame('50.00', $this->otherInventory->fresh()->left_quantity);
        $this->assertSame(0, ServiceRecord::count());
    }

    public function test_store_id_is_required_once_a_record_has_parts()
    {
        $payload = $this->payload([
            ['inventory_id' => $this->inventory->id, 'quantity' => 1, 'amount' => 10],
        ]);
        unset($payload['store_id']);

        $this->postJson('/api/v1/admin/service-records', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('store_id');

        $this->assertSame('20.00', $this->inventory->fresh()->left_quantity);
    }

    public function test_job_card_number_is_required_on_every_service()
    {
        // With parts.
        $payload = $this->payload([
            ['inventory_id' => $this->inventory->id, 'quantity' => 1, 'amount' => 10],
        ]);
        unset($payload['job_card_number']);

        $this->postJson('/api/v1/admin/service-records', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_card_number');

        // And without any.
        $bare = $this->payload([]);
        unset($bare['job_card_number'], $bare['store_id']);
        $bare['spare_parts_changed'] = false;

        $this->postJson('/api/v1/admin/service-records', $bare)
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_card_number');
    }

    public function test_a_record_with_no_parts_needs_no_store()
    {
        $payload = $this->payload([]);
        unset($payload['store_id']);
        $payload['spare_parts_changed'] = false;

        $this->postJson('/api/v1/admin/service-records', $payload)->assertStatus(201);
    }

    public function test_inventory_id_must_exist()
    {
        $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['inventory_id' => 9999, 'quantity' => 1, 'amount' => 10],
        ]))->assertStatus(422)->assertJsonValidationErrors('spare_parts.0.inventory_id');
    }

    public function test_raising_a_part_quantity_deducts_only_the_difference()
    {
        $id = $this->createRecordWithPart(3);

        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'store_id'            => $this->store->id,
            'job_card_number'     => 'JC-2026-0001',
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['inventory_id' => $this->inventory->id, 'quantity' => 5, 'amount' => 100],
            ],
        ])->assertStatus(200);

        // 20 - 5, not 20 - 3 - 5.
        $this->assertSame('15.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame('500.00', ServiceRecord::find($id)->spare_parts_amount_total);
    }

    public function test_lowering_a_part_quantity_returns_the_difference()
    {
        $id = $this->createRecordWithPart(5);
        $this->assertSame('15.00', $this->inventory->fresh()->left_quantity);

        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'store_id'            => $this->store->id,
            'job_card_number'     => 'JC-2026-0001',
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['inventory_id' => $this->inventory->id, 'quantity' => 2, 'amount' => 100],
            ],
        ])->assertStatus(200);

        $this->assertSame('18.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame('200.00', ServiceRecord::find($id)->spare_parts_amount_total);
    }

    public function test_removing_a_part_returns_all_of_it()
    {
        $id = $this->createRecordWithPart(5);

        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'spare_parts_changed' => false,
        ])->assertStatus(200);

        $this->assertSame('20.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame('0.00', ServiceRecord::find($id)->spare_parts_amount_total);
        $this->assertSame(0, \DB::table('service_spare_parts')->where('service_record_id', $id)->count());
    }

    public function test_editing_a_record_preserves_part_prices()
    {
        $id = $this->createRecordWithPart(3);
        $this->assertSame('300.00', ServiceRecord::find($id)->spare_parts_amount_total);

        // Re-post the same list untouched: the money must survive the round trip.
        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'store_id'            => $this->store->id,
            'job_card_number'     => 'JC-2026-0001',
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['inventory_id' => $this->inventory->id, 'quantity' => 3, 'amount' => 100],
            ],
        ])->assertStatus(200);

        $this->assertSame('300.00', ServiceRecord::find($id)->spare_parts_amount_total);
        $this->assertSame('17.00', $this->inventory->fresh()->left_quantity);
    }

    public function test_re_posting_an_unchanged_part_returns_before_it_deducts()
    {
        $second = Product::create([
            'sub_category_id' => $this->product->sub_category_id,
            'name'            => 'Hydraulic Hose HX-12',
            'min_stock'       => 5,
            'is_active'       => 1,
        ]);
        $secondInventory = Inventory::create([
            'store_id'      => $this->store->id,
            'product_id'    => $second->id,
            'quantity'      => 1,
            'left_quantity' => 1,
            'is_active'     => 1,
        ]);

        // The only unit on the shelf goes out, leaving the row empty.
        $id = $this->createRecordWithPart(1, $secondInventory->id);
        $this->assertSame('0.00', $secondInventory->fresh()->left_quantity);

        // Re-posting the same quantity must not fail: nothing net is issued.
        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'store_id'            => $this->store->id,
            'job_card_number'     => 'JC-2026-0001',
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['inventory_id' => $secondInventory->id, 'quantity' => 1, 'amount' => 100],
            ],
        ])->assertStatus(200);

        $this->assertSame('0.00', $secondInventory->fresh()->left_quantity);
    }

    public function test_moving_a_record_to_another_store_returns_and_reissues()
    {
        $id = $this->createRecordWithPart(4);
        $this->assertSame('16.00', $this->inventory->fresh()->left_quantity);

        $this->putJson("/api/v1/admin/service-records/{$id}", [
            'store_id'            => $this->otherStore->id,
            'job_card_number'     => 'JC-2026-0002',
            'spare_parts_changed' => true,
            'spare_parts'         => [
                ['inventory_id' => $this->otherInventory->id, 'quantity' => 4, 'amount' => 100],
            ],
        ])->assertStatus(200);

        // First store fully returned, second store issued — the same product,
        // two separate balances.
        $this->assertSame('20.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame('46.00', $this->otherInventory->fresh()->left_quantity);
        $this->assertSame('400.00', ServiceRecord::find($id)->spare_parts_amount_total);
    }

    public function test_deleting_a_record_returns_its_parts_to_stock()
    {
        Mail::fake();

        $id = $this->postJson('/api/v1/admin/service-records', $this->payload([
            ['product_id' => $this->product->id, 'quantity' => 16, 'amount' => 10],
        ]))->assertStatus(201)->json('data.id');

        // 20 - 16 = 4, under min_stock of 5: a low-stock alert is open.
        $this->assertSame('4.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame(1, InventoryAlert::openLevel()->count());

        $this->deleteJson("/api/v1/admin/service-records/{$id}")->assertStatus(200);

        $this->assertSoftDeleted('service_records', ['id' => $id]);
        $this->assertSame('20.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame('50.00', $this->otherInventory->fresh()->left_quantity);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'store_id'   => $this->store->id,
            'type'       => 'in',
            'action'     => 'service_spare_part_return',
            'quantity'   => 16.00,
        ]);

        // Back above min_stock, so the alert the issue raised is closed.
        $this->assertSame(0, InventoryAlert::openLevel()->count());
        $this->assertSame(1, InventoryAlert::where('type', 'back_in_stock')->count());

        $audit = \App\Models\ServiceAuditLog::where('service_record_id', $id)
            ->where('action', 'deleted')
            ->sole();
        $this->assertCount(1, $audit->changes['spare_parts_returned']);
        $this->assertEquals(16, $audit->changes['spare_parts_returned'][0]['quantity']);

        // A deleted record is gone from the API, so its parts cannot go back twice.
        $this->deleteJson("/api/v1/admin/service-records/{$id}")->assertStatus(404);
        $this->assertSame('20.00', $this->inventory->fresh()->left_quantity);
    }

    public function test_deleting_a_record_without_parts_touches_no_stock()
    {
        $payload = $this->payload([]);
        $payload['spare_parts_changed'] = false;
        unset($payload['spare_parts']);

        $id = $this->postJson('/api/v1/admin/service-records', $payload)
            ->assertStatus(201)
            ->json('data.id');

        $this->deleteJson("/api/v1/admin/service-records/{$id}")->assertStatus(200);

        $this->assertSoftDeleted('service_records', ['id' => $id]);
        $this->assertSame('20.00', $this->inventory->fresh()->left_quantity);
        $this->assertSame(0, \DB::table('inventory_logs')->where('action', 'service_spare_part_return')->count());
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
    protected function createRecordWithPart($quantity, $inventoryId = null)
    {
        return $this->postJson('/api/v1/admin/service-records', $this->payload([
            [
                'inventory_id' => $inventoryId ?: $this->inventory->id,
                'quantity'     => $quantity,
                // The caller prices each unit, so the line comes to 100 * qty.
                'amount'       => 100,
            ],
        ]))->assertStatus(201)->json('data.id');
    }
}
