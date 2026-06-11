<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Department;
use App\Models\Inventory;
use App\Models\InventoryLog;
use App\Models\EmployeeProductAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Mail;
use App\Mail\LowStockAlertMail;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $category;
    protected $subCategory;
    protected $product;
    protected $employee;
    protected $site;
    protected $department;

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

        // Create a default product
        $this->product = Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Test Product',
            'min_stock' => 5,
            'is_active' => 1
        ]);

        // Create a site
        $this->site = Site::create([
            'site_name' => 'East Mine',
            'is_active' => 1
        ]);

        // Create a department
        $this->department = Department::create([
            'name' => 'Safety',
            'is_active' => 1
        ]);

        // Create an employee
        $this->employee = Employee::create([
            'employee_code' => 'EMP002',
            'name' => 'Sanjay Sharma',
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);

        // Authenticate with Sanctum
        Sanctum::actingAs($this->adminUser);
    }

    public function test_can_add_product_to_inventory()
    {
        $response = $this->postJson('/api/v1/admin/inventories/add', [
            'product_id' => $this->product->id,
            'quantity' => 100.50,
            'remarks' => 'Initial stock addition'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'product_name' => 'Test Product',
                'total_stock' => 100.50
            ]);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $this->product->id,
            'quantity' => 100.50
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'type' => 'in',
            'quantity' => 100.50,
            'remarks' => 'Initial stock addition'
        ]);
    }

    public function test_add_product_to_inventory_fails_when_below_min_stock()
    {
        // min_stock is 5
        $response = $this->postJson('/api/v1/admin/inventories/add', [
            'product_id' => $this->product->id,
            'quantity' => 3.00,
            'remarks' => 'Adding low stock'
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Validation failed'
            ])
            ->assertJsonStructure([
                'errors' => [
                    'quantity'
                ]
            ]);
    }

    public function test_can_list_inventory()
    {
        Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 250.00,
            'left_quantity' => 250.00
        ]);

        $response = $this->getJson('/api/v1/admin/inventories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    '*' => ['id', 'product_id', 'product_name', 'category_name', 'sub_category_name', 'total_stock']
                ],
                'pagination'
            ])
            ->assertJsonFragment([
                'product_name' => 'Test Product',
                'total_stock' => 250.00
            ]);
    }

    public function test_can_assign_product_to_employee()
    {
        // Add initial stock first
        Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'left_quantity' => 50.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'issued_date' => '2026-06-10',
            'quantity' => 1.00,
            'remarks' => 'Assigning safety gear'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'employee_name' => 'Sanjay Sharma',
                'product_name' => 'Test Product',
                'quantity' => 1.00
            ]);

        // Verify left_stock was decremented by 1, but original quantity remains same
        $this->assertDatabaseHas('inventories', [
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'left_quantity' => 49.00
        ]);

        // Verify assignment record has quantity 1
        $this->assertDatabaseHas('employee_product_assignments', [
            'employee_id' => $this->employee->id,
            'product_id' => $this->product->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'quantity' => 1.00,
            'issued_date' => '2026-06-10'
        ]);

        // Verify negative transaction log has quantity -1
        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'type' => 'out',
            'quantity' => -1.00
        ]);
    }

    public function test_can_assign_product_to_employee_with_custom_quantity()
    {
        Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'left_quantity' => 50.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'issued_date' => '2026-06-10',
            'quantity' => 5.50,
            'remarks' => 'Assigning multiple safety gears'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'employee_name' => 'Sanjay Sharma',
                'product_name' => 'Test Product',
                'quantity' => 5.50
            ]);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'left_quantity' => 44.50
        ]);

        $this->assertDatabaseHas('employee_product_assignments', [
            'employee_id' => $this->employee->id,
            'product_id' => $this->product->id,
            'quantity' => 5.50,
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'type' => 'out',
            'quantity' => -5.50
        ]);
    }

    public function test_can_assign_product_to_employee_without_site_id()
    {
        // Add initial stock first
        Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'left_quantity' => 50.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'site_id' => null,
            'department_id' => $this->department->id,
            'issued_date' => '2026-06-10',
            'quantity' => 1.00,
            'remarks' => 'Assigning safety gear without site'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'employee_name' => 'Sanjay Sharma',
                'product_name' => 'Test Product',
                'quantity' => 1.00,
                'site_id' => null
            ]);

        // Verify left_stock was decremented by 1, but original quantity remains same
        $this->assertDatabaseHas('inventories', [
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'left_quantity' => 49.00
        ]);

        // Verify assignment record
        $this->assertDatabaseHas('employee_product_assignments', [
            'employee_id' => $this->employee->id,
            'product_id' => $this->product->id,
            'site_id' => null,
            'department_id' => $this->department->id,
            'quantity' => 1.00,
            'issued_date' => '2026-06-10'
        ]);
    }

    public function test_validation_fails_when_assigning_more_than_available_stock()
    {
        // Inventory is 0.5 (less than the default assignment quantity of 1)
        Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 0.50
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'issued_date' => '2026-06-10',
            'quantity' => 1.00,
            'remarks' => 'Assigning safety gear'
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Validation failed'
            ])
            ->assertJsonStructure([
                'errors' => [
                    'quantity'
                ]
            ]);
    }

    public function test_validation_fails_when_product_has_no_inventory()
    {
        // No Inventory record is created for this product
        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'issued_date' => '2026-06-10',
            'quantity' => 1.00,
            'remarks' => 'Assigning safety gear'
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Validation failed'
            ])
            ->assertJsonStructure([
                'errors' => [
                    'quantity'
                ]
            ]);
    }

    public function test_can_get_inventory_logs()
    {
        InventoryLog::create([
            'product_id' => $this->product->id,
            'user_id' => $this->adminUser->id,
            'type' => 'in',
            'action' => 'added',
            'quantity' => 100.00,
            'remarks' => 'Log entry'
        ]);

        $response = $this->getJson("/api/v1/admin/inventories/{$this->product->id}/logs");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'product_name' => 'Test Product',
                'type' => 'in',
                'action' => 'added',
                'quantity' => 100.00,
                'remarks' => 'Log entry',
                'done_by' => 'System-Administrator'
            ]);
    }

    public function test_can_get_assignments()
    {
        EmployeeProductAssignment::create([
            'employee_id' => $this->employee->id,
            'product_id' => $this->product->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'quantity' => 20.00,
            'issued_date' => '2026-06-10'
        ]);

        $response = $this->getJson('/api/v1/admin/inventories/assignments');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'employee_name' => 'Sanjay Sharma',
                'product_name' => 'Test Product',
                'quantity' => 20.00
            ]);
    }

    public function test_inventory_import_processes_rows()
    {
        $import = new \App\Imports\InventoryImport();
        $import->collection(collect([
            collect(['product_name' => 'Test Product', 'quantity' => 10.00]),
            collect(['product_name' => 'Nonexistent Product', 'quantity' => 20.00]),
            collect(['product_name' => 'Test Product', 'quantity' => 1.00]), // Below min_stock which is 5
            collect(['product_name' => 'Test Product', 'quantity' => 15.00]), // Already uploaded (as first row processed it)
        ]));

        $this->assertEquals(1, $import->getSuccessCount());
        $this->assertCount(3, $import->getErrors());
        $this->assertStringContainsString("Product 'Nonexistent Product' not found", $import->getErrors()[0]);
        $this->assertStringContainsString("must be at least 5", $import->getErrors()[1]);
        $this->assertStringContainsString("is already added to inventory", $import->getErrors()[2]);

        // Assert database had stock updated
        $this->assertDatabaseHas('inventories', [
            'product_id' => $this->product->id,
            'quantity' => 10.00
        ]);
    }

    public function test_adding_to_existing_inventory_updates_quantity()
    {
        // Add initial stock
        $this->postJson('/api/v1/admin/inventories/add', [
            'product_id' => $this->product->id,
            'quantity' => 100.50,
            'remarks' => 'Initial stock addition'
        ])->assertStatus(200);

        // Try to add stock again for the same product
        $response = $this->postJson('/api/v1/admin/inventories/add', [
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'remarks' => 'Adding more stock'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'total_stock' => 150.50,
                'left_quantity' => 150.50
            ]);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $this->product->id,
            'quantity' => 150.50,
            'left_quantity' => 150.50
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'type' => 'in',
            'quantity' => 50.00,
            'remarks' => 'Adding more stock'
        ]);
    }

    public function test_bulk_upload_requires_file()
    {
        $response = $this->postJson('/api/v1/admin/inventories/bulk-upload', []);

        $response->assertStatus(422);
    }

    public function test_assign_triggers_low_stock_mail_to_admin_and_supervisor()
    {
        Mail::fake();

        // Create admin and supervisor users
        $adminRole = Role::where('slug', 'super-admin')->first();
        $supervisorRole = Role::where('slug', 'supervisor')->first();

        // We must also create supervisor role since it might not exist in db for tests
        if (!$supervisorRole) {
            $supervisorRole = Role::create([
                'name' => 'Supervisor',
                'slug' => 'supervisor',
                'is_active' => 1
            ]);
        }

        $adminUser = User::create([
            'email' => 'another-admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $adminUser->roles()->attach($adminRole);

        $supervisorUser = User::create([
            'email' => 'supervisor@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $supervisorUser->roles()->attach($supervisorRole);

        // Inventory is 0.00
        Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 0.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'issued_date' => '2026-06-10',
            'quantity' => 1.00
        ]);

        $response->assertStatus(422);

        Mail::assertSent(LowStockAlertMail::class, function ($mail) {
            return $mail->hasTo('another-admin@test.com') && $mail->hasTo('supervisor@test.com');
        });
    }

    public function test_assign_triggers_email_when_left_quantity_drops_below_min_stock()
    {
        Mail::fake();

        // Create admin and supervisor users
        $adminRole = Role::where('slug', 'super-admin')->first();
        $supervisorRole = Role::where('slug', 'supervisor')->first();

        if (!$supervisorRole) {
            $supervisorRole = Role::create([
                'name' => 'Supervisor',
                'slug' => 'supervisor',
                'is_active' => 1
            ]);
        }

        $adminUser = User::create([
            'email' => 'another-admin-2@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $adminUser->roles()->attach($adminRole);

        $supervisorUser = User::create([
            'email' => 'supervisor-2@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $supervisorUser->roles()->attach($supervisorRole);

        // Product min_stock is 5.00
        // Set left_quantity to 6.00. Assigning 1 will make it 5.00 (equal to min_stock)
        Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 6.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'issued_date' => '2026-06-10',
            'quantity' => 1.00
        ]);

        $response->assertStatus(200);

        Mail::assertSent(LowStockAlertMail::class, function ($mail) {
            return $mail->hasTo('another-admin-2@test.com') && $mail->hasTo('supervisor-2@test.com');
        });
    }

    public function test_can_update_inventory_quantity()
    {
        $inventory = Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 100.00,
            'left_quantity' => 50.00
        ]);

        $response = $this->postJson("/api/v1/admin/inventories/update-quantity/{$inventory->id}", [
            'quantity' => 25.00,
            'remarks' => 'Adding more stock'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'total_stock' => 125.00,
                'left_quantity' => 75.00
            ]);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $this->product->id,
            'quantity' => 125.00,
            'left_quantity' => 75.00
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'type' => 'in',
            'quantity' => 25.00,
            'remarks' => 'Adding more stock'
        ]);
    }

    public function test_update_inventory_quantity_fails_when_below_min_stock()
    {
        $inventory = Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 10.00
        ]);

        // product min_stock is 5
        // Decreasing by -6 makes the resulting total quantity 4 (which is below min_stock)
        $response = $this->postJson("/api/v1/admin/inventories/update-quantity/{$inventory->id}", [
            'quantity' => -6.00,
            'remarks' => 'Decreasing below min stock'
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Validation failed'
            ])
            ->assertJsonStructure([
                'errors' => [
                    'quantity'
                ]
            ]);
    }

    public function test_update_inventory_quantity_can_decrease_quantity()
    {
        $inventory = Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 100.00,
            'left_quantity' => 50.00
        ]);

        // product min_stock is 5
        // Decreasing by -20 makes resulting total 80, available 30
        $response = $this->postJson("/api/v1/admin/inventories/update-quantity/{$inventory->id}", [
            'quantity' => -20.00,
            'remarks' => 'Decreasing stock'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'total_stock' => 80.00,
                'left_quantity' => 30.00
            ]);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $this->product->id,
            'quantity' => 80.00,
            'left_quantity' => 30.00
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'type' => 'out',
            'quantity' => -20.00,
            'remarks' => 'Decreasing stock'
        ]);
    }

    public function test_update_inventory_quantity_fails_when_decrease_exceeds_available_stock()
    {
        $inventory = Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 2.00 // 8 are assigned
        ]);

        // Trying to decrease by -3.00, which means new total = 7.00, but 8 are assigned.
        // It should fail and mention that 8 units are already assigned.
        $response = $this->postJson("/api/v1/admin/inventories/update-quantity/{$inventory->id}", [
            'quantity' => -3.00,
            'remarks' => 'Decreasing below assigned'
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Validation failed'
            ])
            ->assertJsonFragment([
                'quantity' => [
                    "Cannot reduce quantity. A total of 8 units of this product are already assigned to employees, which exceeds the proposed total stock of 7."
                ]
            ]);
    }

    public function test_inventory_logs_recording_correct_actions()
    {
        // 1. Added
        $this->postJson('/api/v1/admin/inventories/add', [
            'product_id' => $this->product->id,
            'quantity' => 10.00
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'action' => 'added',
            'quantity' => 10.00
        ]);

        // 2. Replenished
        $this->postJson('/api/v1/admin/inventories/add', [
            'product_id' => $this->product->id,
            'quantity' => 15.00
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'action' => 'replenished',
            'quantity' => 15.00
        ]);

        // 3. Assigned
        $this->postJson('/api/v1/admin/inventories/assign', [
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'issued_date' => '2026-06-10',
            'quantity' => 1.00
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'action' => 'assigned',
            'quantity' => -1.00,
            'remarks' => "Product assigned to employee Code: {$this->employee->employee_code}"
        ]);

        // 4. Edited (decreased)
        $inventory = Inventory::where('product_id', $this->product->id)->first();
        $this->postJson("/api/v1/admin/inventories/update-quantity/{$inventory->id}", [
            'quantity' => -3.00
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'action' => 'edited',
            'quantity' => -3.00
        ]);
    }

    public function test_can_view_particular_inventory_product_details()
    {
        $inventory = Inventory::create([
            'product_id' => $this->product->id,
            'quantity' => 1000.00,
            'left_quantity' => 500.00
        ]);

        EmployeeProductAssignment::create([
            'employee_id' => $this->employee->id,
            'product_id' => $this->product->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'quantity' => 500.00,
            'issued_date' => '2026-05-27'
        ]);

        $response = $this->getJson("/api/v1/admin/inventories/{$inventory->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 200,
                'message' => 'Product details fetched successfully.',
                'data' => [
                    'id' => $inventory->id,
                    'product_name' => 'Test Product',
                    'category_name' => 'Test Category',
                    'sub_category_name' => 'Test SubCategory',
                    'available_stock' => 500.00,
                    'allocation_history' => [
                        [
                            'sr_no' => 1,
                            'employee_name' => 'Sanjay Sharma',
                            'employee_code' => 'EMP002',
                            'site_name' => 'East Mine',
                            'department_name' => 'Safety',
                            'quantity_assigned' => 500.00,
                            'issued_date' => '27 May 2026'
                        ]
                    ]
                ]
            ]);
    }

    public function test_unauthenticated_request_returns_json_error()
    {
        $this->app['auth']->forgetGuards();

        $response = $this->get('/api/v1/admin/inventories');

        $response->assertStatus(401)
            ->assertJson([
                'status' => 401,
                'message' => 'User is not logged in.'
            ]);
    }
}
