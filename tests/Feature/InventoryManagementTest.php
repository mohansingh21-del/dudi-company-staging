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
use App\Models\Store;
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
    protected $store;
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

        // Every stock row belongs to a store; there is no default one.
        $this->store = Store::create([
            'name' => 'Central Store',
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
            'store_id' => $this->store->id,
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
            'store_id' => $this->store->id,
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

        $this->assertDatabaseMissing('inventories', [
            'store_id' => $this->store->id,
            'product_id' => $this->product->id
        ]);
    }

    public function test_can_list_inventory()
    {
        Inventory::create([
            'store_id' => $this->store->id,
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

    /**
     * Rows of a downloaded CSV, header included.
     */
    protected function downloadedCsv($response)
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $lines = array_filter(explode("\n", trim(file_get_contents($path))));

        return array_values(array_map('str_getcsv', $lines));
    }

    public function test_can_export_inventory_as_csv()
    {
        Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 250.00,
            'left_quantity' => 3.00,
            'is_active' => 1
        ]);

        $response = $this->get('/api/v1/admin/inventories/export?format=csv');

        $response->assertStatus(200);
        $this->assertStringContainsString('.csv', $response->headers->get('content-disposition'));

        $rows = $this->downloadedCsv($response);

        $this->assertSame('Sr No', $rows[0][0]);
        $this->assertCount(2, $rows);
        $this->assertNotContains('Total Stock', $rows[0]);
        $this->assertSame(
            ['1', 'Central Store', 'Test Product', 'Test Category', 'Test SubCategory', '3', '5', 'Low Stock', 'Active'],
            array_slice($rows[1], 0, 9)
        );
    }

    public function test_export_defaults_to_excel()
    {
        Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'left_quantity' => 10
        ]);

        $response = $this->get('/api/v1/admin/inventories/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_export_applies_list_filters()
    {
        $otherStore = Store::create(['name' => 'North Store', 'is_active' => 1]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
            'left_quantity' => 100
        ]);
        Inventory::create([
            'store_id' => $otherStore->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'left_quantity' => 0
        ]);

        $rows = $this->downloadedCsv(
            $this->get("/api/v1/admin/inventories/export?format=csv&stock_status=out_of_stock")
        );

        $this->assertCount(2, $rows);
        $this->assertSame('North Store', $rows[1][1]);
        $this->assertSame('Out of Stock', $rows[1][7]);
    }

    public function test_export_rejects_unknown_format()
    {
        $this->getJson('/api/v1/admin/inventories/export?format=pdf')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['format']]);
    }

    public function test_can_assign_product_to_employee()
    {
        // Add initial stock first
        Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'left_quantity' => 50.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'store_id' => $this->store->id,
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
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'left_quantity' => 50.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'store_id' => $this->store->id,
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
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'left_quantity' => 50.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'store_id' => $this->store->id,
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
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 0.50
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'store_id' => $this->store->id,
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

    public function test_validation_fails_when_product_is_not_stocked_at_the_store()
    {
        // No Inventory record is created for this product at this store
        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'store_id' => $this->store->id,
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
                    'product_id'
                ]
            ]);
    }

    public function test_can_get_inventory_logs()
    {
        $inventory = Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 100.00,
            'left_quantity' => 100.00
        ]);

        InventoryLog::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'user_id' => $this->adminUser->id,
            'type' => 'in',
            'action' => 'added',
            'quantity' => 100.00,
            'remarks' => 'Log entry'
        ]);

        $response = $this->getJson("/api/v1/admin/inventories/{$inventory->id}/logs");

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
            'store_id' => $this->store->id,
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
        $import = new \App\Imports\InventoryImport($this->store->id);
        $import->collection(collect([
            collect(['product_name' => 'Test Product', 'quantity' => 10.00]),
            collect(['product_name' => 'Nonexistent Product', 'quantity' => 20.00]),
            collect(['product_name' => 'Test Product', 'quantity' => 1.00]), // Below min_stock which is 5
            collect(['product_name' => 'Test Product', 'quantity' => 15.00]), // Already uploaded (as first row processed it)
        ]));

        $this->assertEquals(1, $import->getSuccessCount());
        $this->assertCount(3, $import->getErrors());
        $this->assertStringContainsString("Product 'Nonexistent Product' not found", $import->getErrors()[0]['message']);
        $this->assertStringContainsString("must be at least 5", $import->getErrors()[1]['message']);
        $this->assertStringContainsString("is already added to this store's inventory", $import->getErrors()[2]['message']);

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
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 100.50,
            'remarks' => 'Initial stock addition'
        ])->assertStatus(200);

        // Try to add stock again for the same product
        $response = $this->postJson('/api/v1/admin/inventories/add', [
            'store_id' => $this->store->id,
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

    public function test_bulk_upload_requires_file_and_store()
    {
        $this->postJson('/api/v1/admin/inventories/bulk-upload', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file', 'store_id']);

        $this->postJson('/api/v1/admin/inventories/bulk-upload', ['store_id' => $this->store->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_assign_emptying_stock_sends_out_of_stock_mail_to_admin_and_supervisor()
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

        // One unit left, already under min_stock of 5. Issuing it is allowed
        // and empties the shelf.
        Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 1.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'issued_date' => '2026-06-10',
            'quantity' => 1.00
        ]);

        $response->assertStatus(200);

        Mail::assertSent(LowStockAlertMail::class, function ($mail) {
            return $mail->outOfStock
                && $mail->hasTo('another-admin@test.com')
                && $mail->hasTo('supervisor@test.com');
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
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 6.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/assign', [
            'store_id' => $this->store->id,
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

    public function test_topping_up_existing_inventory_accepts_quantity_below_min_stock()
    {
        // product min_stock is 5. Stock has run down under the floor; a top-up
        // of 3 is smaller than min_stock but must still be added on.
        $inventory = Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 2.00
        ]);

        $response = $this->postJson('/api/v1/admin/inventories/add', [
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 3.00,
            'remarks' => 'Small top-up'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'total_stock' => 13.00,
                'left_quantity' => 5.00
            ]);

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id,
            'quantity' => 13.00,
            'left_quantity' => 5.00
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'store_id' => $this->store->id,
            'type' => 'in',
            'action' => 'added',
            'quantity' => 3.00,
            'remarks' => 'Small top-up'
        ]);
    }

    public function test_update_quantity_endpoint_is_removed()
    {
        $inventory = Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 10.00
        ]);

        $this->postJson("/api/v1/admin/inventories/update-quantity/{$inventory->id}", [
            'quantity' => -3.00
        ])->assertStatus(404);

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id,
            'quantity' => 10.00,
            'left_quantity' => 10.00
        ]);
    }

    public function test_inventory_logs_recording_correct_actions()
    {
        // 1. Added
        $this->postJson('/api/v1/admin/inventories/add', [
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 10.00
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'action' => 'added',
            'quantity' => 10.00
        ]);

        // 2. Topped up an existing row: still logged as added
        $this->postJson('/api/v1/admin/inventories/add', [
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 15.00
        ]);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $this->product->id,
            'action' => 'added',
            'quantity' => 15.00
        ]);

        // 3. Assigned
        $this->postJson('/api/v1/admin/inventories/assign', [
            'store_id' => $this->store->id,
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
    }

    public function test_can_view_particular_inventory_product_details()
    {
        $inventory = Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 1000.00,
            'left_quantity' => 500.00
        ]);

        EmployeeProductAssignment::create([
            'employee_id' => $this->employee->id,
            'product_id' => $this->product->id,
            'store_id' => $this->store->id,
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

    /*
    |--------------------------------------------------------------------------
    | Store scoping
    |--------------------------------------------------------------------------
    | The same product stocked at two stores is two independent balances. These
    | cover what the old separate store-inventory module used to.
    */

    public function test_same_product_can_be_stocked_at_two_stores()
    {
        $other = Store::create(['name' => 'North Store', 'is_active' => 1]);

        $this->postJson('/api/v1/admin/inventories/add', [
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 40.00
        ])->assertStatus(200);

        $this->postJson('/api/v1/admin/inventories/add', [
            'store_id' => $other->id,
            'product_id' => $this->product->id,
            'quantity' => 25.00
        ])->assertStatus(200);

        $this->assertSame(2, Inventory::where('product_id', $this->product->id)->count());
        $this->assertDatabaseHas('inventories', [
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 40.00
        ]);
        $this->assertDatabaseHas('inventories', [
            'store_id' => $other->id,
            'product_id' => $this->product->id,
            'quantity' => 25.00
        ]);
    }

    public function test_index_can_filter_by_store()
    {
        $other = Store::create(['name' => 'North Store', 'is_active' => 1]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 40.00,
            'left_quantity' => 40.00
        ]);
        Inventory::create([
            'store_id' => $other->id,
            'product_id' => $this->product->id,
            'quantity' => 25.00,
            'left_quantity' => 25.00
        ]);

        // No store_id spans every store.
        $this->getJson('/api/v1/admin/inventories')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $response = $this->getJson("/api/v1/admin/inventories?store_id={$other->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($other->id, $response->json('data.0.store_id'));
        $this->assertSame('North Store', $response->json('data.0.store_name'));
    }

    public function test_index_can_filter_low_stock_against_product_min_stock()
    {
        $spare = Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Well Stocked Product',
            'min_stock' => 5,
            'is_active' => 1
        ]);

        // At its floor of 5 — low.
        Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 5.00,
            'left_quantity' => 5.00
        ]);
        // Above it — not low.
        Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $spare->id,
            'quantity' => 50.00,
            'left_quantity' => 50.00
        ]);

        $response = $this->getJson('/api/v1/admin/inventories?low_stock=1');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($this->product->id, $response->json('data.0.product_id'));
        $this->assertTrue($response->json('data.0.is_low_stock'));
    }

    public function test_available_products_is_scoped_to_a_store_and_hides_only_empty_rows()
    {
        $other = Store::create(['name' => 'North Store', 'is_active' => 1]);
        $third = Store::create(['name' => 'South Store', 'is_active' => 1]);

        // Above min_stock of 5.
        $stocked = Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 20.00,
            'left_quantity' => 20.00
        ]);
        // Under min_stock — low, but still issuable.
        $low = Inventory::create([
            'store_id' => $other->id,
            'product_id' => $this->product->id,
            'quantity' => 5.00,
            'left_quantity' => 3.00
        ]);
        // Empty — nothing to issue.
        Inventory::create([
            'store_id' => $third->id,
            'product_id' => $this->product->id,
            'quantity' => 5.00,
            'left_quantity' => 0.00
        ]);

        $response = $this->getJson("/api/v1/available-products?store_id={$this->store->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($stocked->id, $response->json('data.0.inventory_id'));
        $this->assertEquals(20.0, $response->json('data.0.available_quantity'));
        $this->assertFalse($response->json('data.0.is_low_stock'));

        $lowResponse = $this->getJson("/api/v1/available-products?store_id={$other->id}");

        $lowResponse->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($low->id, $lowResponse->json('data.0.inventory_id'));
        $this->assertEquals(3.0, $lowResponse->json('data.0.available_quantity'));
        $this->assertTrue($lowResponse->json('data.0.is_low_stock'));

        $this->getJson("/api/v1/available-products?store_id={$third->id}")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_assign_only_draws_from_the_named_store()
    {
        $other = Store::create(['name' => 'North Store', 'is_active' => 1]);

        Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 50.00,
            'left_quantity' => 50.00
        ]);

        // Stocked at $this->store, not at $other.
        $this->postJson('/api/v1/admin/inventories/assign', [
            'store_id' => $other->id,
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'department_id' => $this->department->id,
            'issued_date' => '2026-06-10',
            'quantity' => 1.00
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');

        $this->assertSame('50.00', Inventory::where('store_id', $this->store->id)->first()->left_quantity);
    }

    public function test_logs_are_scoped_to_one_stores_movements()
    {
        $other = Store::create(['name' => 'North Store', 'is_active' => 1]);

        $mine = Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 10.00
        ]);

        InventoryLog::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'user_id' => $this->adminUser->id,
            'type' => 'in',
            'action' => 'added',
            'quantity' => 10.00,
            'remarks' => 'Central movement'
        ]);
        InventoryLog::create([
            'store_id' => $other->id,
            'product_id' => $this->product->id,
            'user_id' => $this->adminUser->id,
            'type' => 'in',
            'action' => 'added',
            'quantity' => 99.00,
            'remarks' => 'North movement'
        ]);

        $response = $this->getJson("/api/v1/admin/inventories/{$mine->id}/logs");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['remarks' => 'Central movement', 'store_name' => 'Central Store'])
            ->assertJsonMissing(['remarks' => 'North movement']);
    }

    public function test_can_remove_a_product_from_a_store_only_once_its_stock_is_zero()
    {
        $inventory = Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => 10.00,
            'left_quantity' => 10.00
        ]);

        $this->deleteJson("/api/v1/admin/inventories/{$inventory->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id]);

        $inventory->update(['left_quantity' => 0.00]);

        $this->deleteJson("/api/v1/admin/inventories/{$inventory->id}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('inventories', ['id' => $inventory->id]);
    }

    public function test_add_requires_a_store()
    {
        $this->postJson('/api/v1/admin/inventories/add', [
            'product_id' => $this->product->id,
            'quantity' => 10.00
        ])->assertStatus(422)->assertJsonValidationErrors('store_id');
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
