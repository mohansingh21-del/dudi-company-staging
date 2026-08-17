<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;

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

        // Authenticate with Sanctum
        Sanctum::actingAs($this->adminUser);
    }

    public function test_can_list_categories()
    {
        Category::create(['name' => 'Category A', 'is_active' => 1]);
        Category::create(['name' => 'Category B', 'is_active' => 1]);

        $response = $this->getJson('/api/v1/admin/categories');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    '*' => ['id', 'name', 'status']
                ],
                'pagination'
            ]);
    }

    public function test_can_create_category()
    {
        $response = $this->postJson('/api/v1/admin/categories', [
            'name' => 'New Category'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'New Category',
                'status' => 1
            ]);

        $this->assertDatabaseHas('categories', [
            'name' => 'New Category'
        ]);
    }

    public function test_validation_fails_on_duplicate_category_name()
    {
        Category::create(['name' => 'Duplicate Category', 'is_active' => 1]);

        $response = $this->postJson('/api/v1/admin/categories', [
            'name' => 'Duplicate Category'
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Validation failed'
            ]);
    }

    public function test_can_show_category()
    {
        $category = Category::create(['name' => 'Show Category', 'is_active' => 1]);

        $response = $this->getJson("/api/v1/admin/categories/{$category->id}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Show Category'
            ]);
    }

    public function test_can_update_category()
    {
        $category = Category::create(['name' => 'Old Name', 'is_active' => 1]);

        $response = $this->putJson("/api/v1/admin/categories/{$category->id}", [
            'name' => 'Updated Name'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Name'
            ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Updated Name'
        ]);
    }

    public function test_can_update_category_via_post()
    {
        $category = Category::create(['name' => 'Old Name POST', 'is_active' => 1]);

        $response = $this->postJson("/api/v1/admin/categories/{$category->id}", [
            'name' => 'Updated Name POST'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Name POST'
            ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Updated Name POST'
        ]);
    }

    public function test_can_toggle_category_status()
    {
        $category = Category::create(['name' => 'Toggle Category', 'is_active' => 1]);

        $response = $this->patchJson("/api/v1/admin/categories/{$category->id}/status");

        $response->assertStatus(200);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'is_active' => 0
        ]);
    }

    public function test_can_delete_category()
    {
        $category = Category::create(['name' => 'To Delete', 'is_active' => 1]);

        $response = $this->deleteJson("/api/v1/admin/categories/{$category->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id
        ]);
    }

    public function test_can_list_subcategories()
    {
        $category = Category::create(['name' => 'Category A', 'is_active' => 1]);
        SubCategory::create(['category_id' => $category->id, 'name' => 'Sub A', 'is_active' => 1]);

        $response = $this->getJson('/api/v1/admin/subcategories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    '*' => ['id', 'category_id', 'category_name', 'name', 'status']
                ],
                'pagination'
            ]);
    }

    public function test_can_create_subcategory()
    {
        $category = Category::create(['name' => 'Category A', 'is_active' => 1]);

        $response = $this->postJson('/api/v1/admin/subcategories', [
            'category_id' => $category->id,
            'name' => 'Sub A'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'category_id' => $category->id,
                'name' => 'Sub A',
                'status' => 1
            ]);

        $this->assertDatabaseHas('sub_categories', [
            'category_id' => $category->id,
            'name' => 'Sub A'
        ]);
    }

    public function test_can_update_subcategory()
    {
        $category = Category::create(['name' => 'Category A', 'is_active' => 1]);
        $subcategory = SubCategory::create(['category_id' => $category->id, 'name' => 'Old Sub', 'is_active' => 1]);

        $response = $this->putJson("/api/v1/admin/subcategories/{$subcategory->id}", [
            'category_id' => $category->id,
            'name' => 'New Sub'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'New Sub'
            ]);

        $this->assertDatabaseHas('sub_categories', [
            'id' => $subcategory->id,
            'name' => 'New Sub'
        ]);
    }

    public function test_can_update_subcategory_via_post()
    {
        $category = Category::create(['name' => 'Category A', 'is_active' => 1]);
        $subcategory = SubCategory::create(['category_id' => $category->id, 'name' => 'Old Sub POST', 'is_active' => 1]);

        $response = $this->postJson("/api/v1/admin/subcategories/{$subcategory->id}", [
            'category_id' => $category->id,
            'name' => 'New Sub POST'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'New Sub POST'
            ]);

        $this->assertDatabaseHas('sub_categories', [
            'id' => $subcategory->id,
            'name' => 'New Sub POST'
        ]);
    }

    public function test_can_toggle_subcategory_status()
    {
        $category = Category::create(['name' => 'Category A', 'is_active' => 1]);
        $subcategory = SubCategory::create(['category_id' => $category->id, 'name' => 'Sub A', 'is_active' => 1]);

        $response = $this->patchJson("/api/v1/admin/subcategories/{$subcategory->id}/status");

        $response->assertStatus(200);

        $this->assertDatabaseHas('sub_categories', [
            'id' => $subcategory->id,
            'is_active' => 0
        ]);
    }

    public function test_can_delete_subcategory()
    {
        $category = Category::create(['name' => 'Category A', 'is_active' => 1]);
        $subcategory = SubCategory::create(['category_id' => $category->id, 'name' => 'Sub A', 'is_active' => 1]);

        $response = $this->deleteJson("/api/v1/admin/subcategories/{$subcategory->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('sub_categories', [
            'id' => $subcategory->id
        ]);
    }

    public function test_unauthenticated_request_returns_json_error()
    {
        // Sign out / remove actingAs
        $this->app['auth']->forgetGuards();

        // Perform request without Sanctum auth using standard get() (so no Accept: application/json header is automatically set)
        $response = $this->get('/api/v1/admin/categories');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 401,
                'message' => 'User is not logged in.'
            ]);
    }
}
