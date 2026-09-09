<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;

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
    }

    public function test_can_create_store()
    {
        $response = $this->postJson('/api/v1/admin/stores', [
            'name'        => 'ABC Traders',
            'description' => 'Outside spare parts store',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 200);

        $this->assertDatabaseHas('stores', [
            'name'      => 'ABC Traders',
            'is_active' => 1,
        ]);
    }

    public function test_validation_fails_on_duplicate_store_name()
    {
        Store::create(['name' => 'ABC Traders', 'is_active' => 1]);

        $this->postJson('/api/v1/admin/stores', ['name' => 'ABC Traders'])
            ->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonValidationErrors('name');
    }

    public function test_can_list_and_search_stores()
    {
        Store::create(['name' => 'ABC Traders', 'description' => 'Filters', 'is_active' => 1]);
        Store::create(['name' => 'XYZ Spares', 'description' => 'Hoses', 'is_active' => 1]);

        $this->getJson('/api/v1/admin/stores')
            ->assertStatus(200)
            ->assertJsonPath('pagination.total', 2);

        $this->getJson('/api/v1/admin/stores?search=XYZ')
            ->assertStatus(200)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.name', 'XYZ Spares');
    }

    public function test_can_show_update_and_toggle_store()
    {
        $store = Store::create(['name' => 'ABC Traders', 'is_active' => 1]);

        $this->getJson("/api/v1/admin/stores/{$store->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'ABC Traders')
            ->assertJsonPath('data.products_count', 0);

        // POST override, the route FormData clients use.
        $this->postJson("/api/v1/admin/stores/{$store->id}", [
            'name'        => 'ABC Traders & Sons',
            'description' => 'Renamed',
        ])->assertStatus(200);

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'name' => 'ABC Traders & Sons']);

        $this->patchJson("/api/v1/admin/stores/{$store->id}/status", ['status' => 0])
            ->assertStatus(200);

        $this->assertDatabaseHas('stores', ['id' => $store->id, 'is_active' => 0]);
    }

    public function test_toggle_status_rejects_invalid_value()
    {
        $store = Store::create(['name' => 'ABC Traders', 'is_active' => 1]);

        $this->patchJson("/api/v1/admin/stores/{$store->id}/status", ['status' => 7])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_can_delete_store_without_mapped_products()
    {
        $store = Store::create(['name' => 'ABC Traders', 'is_active' => 1]);

        $this->deleteJson("/api/v1/admin/stores/{$store->id}")->assertStatus(200);

        $this->assertDatabaseMissing('stores', ['id' => $store->id]);
    }

    public function test_cannot_delete_store_that_still_holds_products()
    {
        $store = Store::create(['name' => 'ABC Traders', 'is_active' => 1]);
        $product = $this->makeProduct();

        StoreProduct::create([
            'store_id'      => $store->id,
            'product_id'    => $product->id,
            'quantity'      => 10,
            'left_quantity' => 10,
            'threshold'     => 2,
            'is_active'     => 1,
        ]);

        $this->deleteJson("/api/v1/admin/stores/{$store->id}")
            ->assertStatus(422)
            ->assertJsonPath('status', 422);

        // The stock and its history must survive the refused delete.
        $this->assertDatabaseHas('stores', ['id' => $store->id]);
        $this->assertDatabaseHas('store_products', ['store_id' => $store->id]);
    }

    public function test_public_index_returns_active_stores_only()
    {
        Store::create(['name' => 'Active Store', 'is_active' => 1]);
        Store::create(['name' => 'Retired Store', 'is_active' => 0]);

        $response = $this->getJson('/api/v1/stores');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Store');
    }

    public function test_unauthenticated_request_returns_json_error()
    {
        app()['auth']->forgetGuards();

        $this->getJson('/api/v1/admin/stores')
            ->assertStatus(401)
            ->assertJsonPath('status', 401);
    }

    protected function makeProduct($name = 'Oil Filter XP-90')
    {
        $category = Category::firstOrCreate(['name' => 'Spare Parts']);
        $subCategory = SubCategory::firstOrCreate([
            'category_id' => $category->id,
            'name'        => 'Filters',
        ]);

        return Product::create([
            'sub_category_id' => $subCategory->id,
            'name'            => $name,
            'min_stock'       => 5,
            'is_active'       => 1,
        ]);
    }
}
