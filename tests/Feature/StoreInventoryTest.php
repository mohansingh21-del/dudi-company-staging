<?php

namespace Tests\Feature;

use App\Mail\LowStockAlertMail;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $store;
    protected $product;

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

        $this->store = Store::create(['name' => 'ABC Traders', 'is_active' => 1]);

        $category = Category::create(['name' => 'Spare Parts']);
        $subCategory = SubCategory::create(['category_id' => $category->id, 'name' => 'Filters']);
        $this->product = Product::create([
            'sub_category_id' => $subCategory->id,
            'name'            => 'Oil Filter XP-90',
            'min_stock'       => 5,
            'is_active'       => 1
        ]);
    }

    public function test_can_map_product_to_store_with_opening_quantity_and_threshold()
    {
        $response = $this->postJson('/api/v1/admin/store-products/add', [
            'store_id'   => $this->store->id,
            'product_id' => $this->product->id,
            'quantity'   => 20,
            'threshold'  => 4,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.left_quantity', 20)
            ->assertJsonPath('data.threshold', 4)
            ->assertJsonPath('data.available_quantity', 16);

        $this->assertDatabaseHas('store_products', [
            'store_id'      => $this->store->id,
            'product_id'    => $this->product->id,
            'quantity'      => 20.00,
            'left_quantity' => 20.00,
            'threshold'     => 4.00,
        ]);

        // The movement is logged against the store, not the mine's own stock.
        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'store_id'   => $this->store->id,
            'type'       => 'in',
            'action'     => 'added',
        ]);
    }

    public function test_remapping_the_same_pair_replenishes_instead_of_duplicating()
    {
        $this->postJson('/api/v1/admin/store-products/add', [
            'store_id'   => $this->store->id,
            'product_id' => $this->product->id,
            'quantity'   => 20,
            'threshold'  => 4,
        ])->assertStatus(200);

        $this->postJson('/api/v1/admin/store-products/add', [
            'store_id'   => $this->store->id,
            'product_id' => $this->product->id,
            'quantity'   => 5,
        ])->assertStatus(200);

        $this->assertSame(1, StoreProduct::where('store_id', $this->store->id)->count());

        $this->assertDatabaseHas('store_products', [
            'store_id'      => $this->store->id,
            'product_id'    => $this->product->id,
            'quantity'      => 25.00,
            'left_quantity' => 25.00,
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'store_id' => $this->store->id,
            'action'   => 'replenished',
        ]);
    }

    public function test_update_quantity_sets_the_total_in_both_directions()
    {
        $storeProduct = $this->mapProduct(20, 4);

        $this->postJson("/api/v1/admin/store-products/update-quantity/{$storeProduct->id}", [
            'quantity' => 30,
        ])->assertStatus(200)->assertJsonPath('data.left_quantity', 30);

        $this->postJson("/api/v1/admin/store-products/update-quantity/{$storeProduct->id}", [
            'quantity' => 22,
        ])->assertStatus(200)->assertJsonPath('data.left_quantity', 22);

        $this->assertDatabaseHas('store_products', [
            'id'            => $storeProduct->id,
            'quantity'      => 22.00,
            'left_quantity' => 22.00,
        ]);
    }

    /**
     * The total is absolute, but what has already gone out of the store is not
     * editable — so left_quantity lands at (new total - already issued).
     */
    public function test_update_quantity_preserves_units_already_issued()
    {
        $storeProduct = $this->mapProduct(20, 4);
        $storeProduct->left_quantity = 12;   // 8 already issued
        $storeProduct->save();

        $this->postJson("/api/v1/admin/store-products/update/{$storeProduct->id}", [
            'quantity' => 50,
        ])->assertStatus(200)
            ->assertJsonPath('data.total_stock', 50)
            ->assertJsonPath('data.left_quantity', 42);

        $this->postJson("/api/v1/admin/store-products/update/{$storeProduct->id}", [
            'quantity' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_update_quantity_cannot_take_total_below_threshold()
    {
        $storeProduct = $this->mapProduct(20, 15);

        $this->postJson("/api/v1/admin/store-products/update-quantity/{$storeProduct->id}", [
            'quantity' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('quantity');

        $this->assertDatabaseHas('store_products', [
            'id'            => $storeProduct->id,
            'left_quantity' => 20.00,
        ]);
    }

    public function test_update_changes_threshold_only()
    {
        $storeProduct = $this->mapProduct(20, 4);

        $this->postJson("/api/v1/admin/store-products/update/{$storeProduct->id}", [
            'threshold' => 9,
        ])->assertStatus(200)->assertJsonPath('data.threshold', 9);

        $this->assertDatabaseHas('store_products', [
            'id'            => $storeProduct->id,
            'threshold'     => 9.00,
            'left_quantity' => 20.00,
        ]);
    }

    public function test_update_changes_quantity_and_threshold_in_one_call()
    {
        $storeProduct = $this->mapProduct(20, 4);

        $this->postJson("/api/v1/admin/store-products/update/{$storeProduct->id}", [
            'quantity'  => 30,
            'threshold' => 9,
            'is_active' => 0,
            'remarks'   => 'Restocked and floor raised',
        ])->assertStatus(200)
            ->assertJsonPath('data.left_quantity', 30)
            ->assertJsonPath('data.threshold', 9)
            ->assertJsonPath('data.status', 0);

        $this->assertDatabaseHas('store_products', [
            'id'            => $storeProduct->id,
            'quantity'      => 30.00,
            'left_quantity' => 30.00,
            'threshold'     => 9.00,
            'is_active'     => 0,
        ]);

        // One edit, one movement line.
        $this->assertDatabaseHas('inventory_logs', [
            'store_id'   => $this->store->id,
            'product_id' => $this->product->id,
            'action'     => 'replenished',
            'quantity'   => 10.00,
            'remarks'    => 'Restocked and floor raised',
        ]);
    }

    public function test_update_validates_new_quantity_against_the_new_threshold()
    {
        $storeProduct = $this->mapProduct(20, 4);

        // A total of 15 is fine against the old floor of 4 but not against 18.
        $this->postJson("/api/v1/admin/store-products/update/{$storeProduct->id}", [
            'quantity'  => 15,
            'threshold' => 18,
        ])->assertStatus(422)->assertJsonValidationErrors('quantity');

        $this->assertDatabaseHas('store_products', [
            'id'            => $storeProduct->id,
            'quantity'      => 20.00,
            'threshold'     => 4.00,
            'left_quantity' => 20.00,
        ]);
    }

    public function test_update_rejects_a_request_that_changes_nothing()
    {
        $storeProduct = $this->mapProduct(20, 4);

        $this->postJson("/api/v1/admin/store-products/update/{$storeProduct->id}", [
            'remarks' => 'no fields to change',
        ])->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_deprecated_update_quantity_alias_still_changes_stock()
    {
        $storeProduct = $this->mapProduct(20, 4);

        $this->postJson("/api/v1/admin/store-products/update-quantity/{$storeProduct->id}", [
            'quantity' => 27,
        ])->assertStatus(200)->assertJsonPath('data.left_quantity', 27);
    }

    public function test_available_endpoint_excludes_stock_at_or_below_threshold()
    {
        $atFloor = $this->mapProduct(4, 4);

        $second = Product::create([
            'sub_category_id' => $this->product->sub_category_id,
            'name'            => 'Hydraulic Hose HX-12',
            'min_stock'       => 0,
            'is_active'       => 1,
        ]);
        StoreProduct::create([
            'store_id'      => $this->store->id,
            'product_id'    => $second->id,
            'quantity'      => 10,
            'left_quantity' => 10,
            'threshold'     => 2,
            'is_active'     => 1,
        ]);

        $response = $this->getJson("/api/v1/admin/store-products/available?store_id={$this->store->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $second->id)
            ->assertJsonPath('data.0.available_quantity', 8);

        $this->assertNotContains(
            $atFloor->id,
            array_column($response->json('data'), 'store_product_id')
        );
    }

    public function test_low_stock_filter_lists_rows_at_or_under_threshold()
    {
        $this->mapProduct(4, 4);

        $second = Product::create([
            'sub_category_id' => $this->product->sub_category_id,
            'name'            => 'Hydraulic Hose HX-12',
            'min_stock'       => 0,
            'is_active'       => 1,
        ]);
        StoreProduct::create([
            'store_id'      => $this->store->id,
            'product_id'    => $second->id,
            'quantity'      => 10,
            'left_quantity' => 10,
            'threshold'     => 2,
            'is_active'     => 1,
        ]);

        $this->getJson("/api/v1/admin/store-products?store_id={$this->store->id}&low_stock=1")
            ->assertStatus(200)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.product_id', $this->product->id)
            ->assertJsonPath('data.0.is_low_stock', true);
    }

    public function test_cannot_unmap_a_product_that_still_has_stock()
    {
        $storeProduct = $this->mapProduct(20, 4);

        $this->deleteJson("/api/v1/admin/store-products/{$storeProduct->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('store_products', ['id' => $storeProduct->id]);

        $this->postJson("/api/v1/admin/store-products/update-quantity/{$storeProduct->id}", [
            'quantity' => -20,
        ])->assertStatus(422); // blocked by the threshold, which is the point

        // Drop the floor first, then the stock, and only then can it be unmapped.
        $this->postJson("/api/v1/admin/store-products/update/{$storeProduct->id}", ['threshold' => 0])
            ->assertStatus(200);
        $this->postJson("/api/v1/admin/store-products/update-quantity/{$storeProduct->id}", [
            'quantity' => 0,
        ])->assertStatus(200);

        $this->deleteJson("/api/v1/admin/store-products/{$storeProduct->id}")->assertStatus(200);
        $this->assertDatabaseMissing('store_products', ['id' => $storeProduct->id]);
    }

    public function test_same_product_holds_independent_balances_in_both_inventories()
    {
        $ownInventory = Inventory::create([
            'product_id'    => $this->product->id,
            'quantity'      => 50,
            'left_quantity' => 50,
        ]);

        $secondStore = Store::create(['name' => 'XYZ Spares', 'is_active' => 1]);

        $atAbc = $this->mapProduct(20, 4);
        $atXyz = StoreProduct::create([
            'store_id'      => $secondStore->id,
            'product_id'    => $this->product->id,
            'quantity'      => 7,
            'left_quantity' => 7,
            'threshold'     => 1,
            'is_active'     => 1,
        ]);

        // Moving one store's stock leaves the other store and the mine's own
        // inventory untouched — the catalog is shared, the balances are not.
        $this->postJson("/api/v1/admin/store-products/update-quantity/{$atAbc->id}", [
            'quantity' => 15,
        ])->assertStatus(200);

        $this->assertSame('15.00', StoreProduct::find($atAbc->id)->left_quantity);
        $this->assertSame('7.00', StoreProduct::find($atXyz->id)->left_quantity);
        $this->assertSame('50.00', Inventory::find($ownInventory->id)->left_quantity);
    }

    public function test_a_product_cannot_be_mapped_twice_to_the_same_store()
    {
        $this->mapProduct(20, 4);

        $this->expectException(\Illuminate\Database\QueryException::class);

        StoreProduct::create([
            'store_id'      => $this->store->id,
            'product_id'    => $this->product->id,
            'quantity'      => 5,
            'left_quantity' => 5,
            'threshold'     => 1,
            'is_active'     => 1,
        ]);
    }

    public function test_store_logs_are_kept_out_of_own_inventory_logs()
    {
        Inventory::create([
            'product_id'    => $this->product->id,
            'quantity'      => 50,
            'left_quantity' => 50,
        ]);

        $this->mapProduct(20, 4);

        // The store mapping wrote a log for this product; the own-inventory
        // endpoint must not show it.
        $this->getJson("/api/v1/admin/inventories/{$this->product->id}/logs")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_mapping_requires_an_existing_store_and_product()
    {
        $this->postJson('/api/v1/admin/store-products/add', [
            'store_id'   => 9999,
            'product_id' => 9999,
            'quantity'   => 5,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['store_id', 'product_id']);
    }

    public function test_available_endpoint_requires_a_store()
    {
        $this->getJson('/api/v1/admin/store-products/available')
            ->assertStatus(422)
            ->assertJsonValidationErrors('store_id');
    }

    public function test_store_stock_logs_are_listed_for_the_pair()
    {
        // Mapped through the API so the opening stock is logged too.
        $storeProductId = $this->postJson('/api/v1/admin/store-products/add', [
            'store_id'   => $this->store->id,
            'product_id' => $this->product->id,
            'quantity'   => 20,
            'threshold'  => 4,
        ])->assertStatus(200)->json('data.id');

        $this->postJson("/api/v1/admin/store-products/update-quantity/{$storeProductId}", [
            'quantity' => 25,
            'remarks'  => 'Restocked after delivery',
        ])->assertStatus(200);

        $this->getJson("/api/v1/admin/store-products/{$storeProductId}/logs")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /**
     * @return StoreProduct
     */
    protected function mapProduct($quantity, $threshold)
    {
        return StoreProduct::create([
            'store_id'      => $this->store->id,
            'product_id'    => $this->product->id,
            'quantity'      => $quantity,
            'left_quantity' => $quantity,
            'threshold'     => $threshold,
            'is_active'     => 1,
        ]);
    }
}
