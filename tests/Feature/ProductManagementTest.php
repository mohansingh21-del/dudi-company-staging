<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $category;
    protected $subCategory;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the super-admin role
        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        // Create super-admin user
        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($role);

        // Create a default category
        $this->category = Category::create([
            'name' => 'Test Category',
            'is_active' => 1
        ]);

        // Create a default subcategory
        $this->subCategory = SubCategory::create([
            'category_id' => $this->category->id,
            'name' => 'Test SubCategory',
            'is_active' => 1
        ]);

        // Authenticate with Sanctum
        Sanctum::actingAs($this->adminUser);
    }

    public function test_can_list_products()
    {
        Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Product A',
            'min_stock' => 5,
            'is_active' => 1
        ]);

        Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Product B',
            'min_stock' => 10,
            'is_active' => 1
        ]);

        $response = $this->getJson('/api/v1/admin/products');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'sub_category_id',
                        'sub_category_name',
                        'category_id',
                        'category_name',
                        'name',
                        'min_stock',
                        'status'
                    ]
                ],
                'pagination'
            ]);
    }

    public function test_can_create_product()
    {
        $response = $this->postJson('/api/v1/admin/products', [
            'sub_category_id' => $this->subCategory->id,
            'name' => 'New Product',
            'min_stock' => 5
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'New Product',
                'min_stock' => 5,
                'status' => 1
            ]);

        $this->assertDatabaseHas('products', [
            'name' => 'New Product',
            'min_stock' => 5
        ]);
    }

    public function test_validation_fails_on_duplicate_product_name()
    {
        Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Duplicate Product',
            'min_stock' => 5,
            'is_active' => 1
        ]);

        $response = $this->postJson('/api/v1/admin/products', [
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Duplicate Product',
            'min_stock' => 10
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Validation failed'
            ]);
    }

    public function test_can_show_product()
    {
        $product = Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Show Product',
            'min_stock' => 3,
            'is_active' => 1
        ]);

        $response = $this->getJson("/api/v1/admin/products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Show Product',
                'min_stock' => 3
            ]);
    }

    public function test_can_update_product()
    {
        $product = Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Old Product',
            'min_stock' => 2,
            'is_active' => 1
        ]);

        $response = $this->putJson("/api/v1/admin/products/{$product->id}", [
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Updated Product',
            'min_stock' => 8
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Product',
                'min_stock' => 8
            ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Product',
            'min_stock' => 8
        ]);
    }

    public function test_can_update_product_via_post()
    {
        $product = Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Old Product POST',
            'min_stock' => 2,
            'is_active' => 1
        ]);

        $response = $this->postJson("/api/v1/admin/products/{$product->id}", [
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Updated Product POST',
            'min_stock' => 12
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Product POST',
                'min_stock' => 12
            ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Product POST',
            'min_stock' => 12
        ]);
    }

    public function test_can_toggle_product_status()
    {
        $product = Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Toggle Product',
            'min_stock' => 5,
            'is_active' => 1
        ]);

        $response = $this->patchJson("/api/v1/admin/products/{$product->id}/status");

        $response->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'is_active' => 0
        ]);
    }

    public function test_can_delete_product()
    {
        $product = Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'To Delete',
            'min_stock' => 4,
            'is_active' => 1
        ]);

        $response = $this->deleteJson("/api/v1/admin/products/{$product->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('products', [
            'id' => $product->id
        ]);
    }

    public function test_can_list_public_products()
    {
        Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Product Active',
            'min_stock' => 2,
            'is_active' => 1
        ]);

        Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Product Inactive',
            'min_stock' => 2,
            'is_active' => 0
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'name' => 'Product Active'
            ]);
    }

    public function test_unauthenticated_request_returns_json_error()
    {
        $this->app['auth']->forgetGuards();

        $response = $this->get('/api/v1/admin/products');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 401,
                'message' => 'User is not logged in.'
            ]);
    }
}
