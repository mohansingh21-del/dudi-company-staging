<?php

namespace Tests\Feature;

use App\Mail\LowStockAlertMail;
use App\Models\Category;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeProductAssignment;
use App\Models\Inventory;
use App\Models\InventoryAlert;
use App\Models\Product;
use App\Models\Role;
use App\Models\Site;
use App\Models\Store;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * min_stock is a warning line, not a floor: issuing under it is allowed and
 * raises an alert. Only an empty shelf blocks an issue.
 */
class InventoryAlertTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $subCategory;
    protected $product;
    protected $store;
    protected $employee;
    protected $site;
    protected $department;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $role = Role::create(['name' => 'System-Administrator', 'slug' => 'super-admin', 'is_active' => 1]);

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($role);

        $category = Category::create(['name' => 'Safety Gear', 'is_active' => 1]);
        $this->subCategory = SubCategory::create(['category_id' => $category->id, 'name' => 'Helmets', 'is_active' => 1]);

        $this->product = Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Safety Helmet',
            'min_stock' => 5,
            'is_active' => 1
        ]);

        $this->store = Store::create(['name' => 'Central Store', 'is_active' => 1]);
        $this->site = Site::create(['site_name' => 'East Mine', 'is_active' => 1]);
        $this->department = Department::create(['name' => 'Safety', 'is_active' => 1]);

        $this->employee = Employee::create([
            'employee_code' => 'EMP002',
            'name' => 'Sanjay Sharma',
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);

        Sanctum::actingAs($this->adminUser);
    }

    protected function stock($leftQuantity, $product = null)
    {
        return Inventory::create([
            'store_id' => $this->store->id,
            'product_id' => ($product ?: $this->product)->id,
            'quantity' => max(20, $leftQuantity),
            'left_quantity' => $leftQuantity,
            'is_active' => 1
        ]);
    }

    protected function assign($quantity)
    {
        return $this->postJson('/api/v1/admin/inventories/assign', [
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'site_id' => $this->site->id,
            'department_id' => $this->department->id,
            'issued_date' => '2026-09-14',
            'quantity' => $quantity
        ]);
    }

    protected function add($quantity)
    {
        return $this->postJson('/api/v1/admin/inventories/add', [
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity
        ]);
    }

    protected function alertsOfType($type)
    {
        return InventoryAlert::where('type', $type)->get();
    }

    public function test_assigning_below_min_stock_is_allowed_and_raises_a_low_stock_alert()
    {
        $inventory = $this->stock(10);

        // 10 - 7 = 3, under min_stock of 5.
        $this->assign(7)->assertStatus(200);

        $this->assertSame('3.00', $inventory->fresh()->left_quantity);

        $alert = $this->alertsOfType('low_stock')->sole();
        $this->assertSame('warning', $alert->severity);
        $this->assertSame($inventory->id, $alert->inventory_id);
        $this->assertSame('3.00', $alert->left_quantity);
        $this->assertSame('5.00', $alert->min_stock);
        $this->assertSame('assignment', $alert->source);
        $this->assertSame('Employee Code: EMP002', $alert->reference);
        $this->assertSame($this->adminUser->id, (int) $alert->triggered_by);
        $this->assertNull($alert->resolved_at);

        Mail::assertSent(LowStockAlertMail::class, function ($mail) {
            return !$mail->outOfStock && $mail->storeName === 'Central Store';
        });
    }

    public function test_low_stock_alert_is_raised_once_while_stock_stays_low()
    {
        $this->stock(10);

        $this->assign(6)->assertStatus(200); // 4 left
        $this->assign(1)->assertStatus(200); // 3 left
        $this->assign(1)->assertStatus(200); // 2 left

        $this->assertCount(1, $this->alertsOfType('low_stock'));
        Mail::assertSent(LowStockAlertMail::class, 1);
    }

    public function test_assigning_can_empty_the_shelf_and_out_of_stock_supersedes_low_stock()
    {
        $inventory = $this->stock(6);

        $this->assign(2)->assertStatus(200); // 4 left: low
        $this->assign(4)->assertStatus(200); // 0 left: out

        $this->assertSame('0.00', $inventory->fresh()->left_quantity);

        $this->assertNotNull($this->alertsOfType('low_stock')->sole()->resolved_at);

        $out = $this->alertsOfType('out_of_stock')->sole();
        $this->assertSame('critical', $out->severity);
        $this->assertNull($out->resolved_at);

        Mail::assertSent(LowStockAlertMail::class, function ($mail) {
            return $mail->outOfStock;
        });
    }

    public function test_assigning_more_than_is_left_is_rejected_without_alerts()
    {
        $inventory = $this->stock(2);

        $this->assign(3)
            ->assertStatus(422)
            ->assertJsonFragment([
                'quantity' => ["Only 2 units of 'Safety Helmet' are left at 'Central Store'."]
            ]);

        $this->assertSame('2.00', $inventory->fresh()->left_quantity);
        $this->assertSame(0, EmployeeProductAssignment::count());
        $this->assertSame(0, InventoryAlert::count());

        $inventory->update(['left_quantity' => 0]);

        $this->assign(1)
            ->assertStatus(422)
            ->assertJsonFragment([
                'quantity' => ["'Safety Helmet' is out of stock at 'Central Store'. Add stock before issuing it."]
            ]);
    }

    public function test_restocking_above_min_stock_resolves_alerts_and_reports_back_in_stock()
    {
        $inventory = $this->stock(6);
        $this->assign(6)->assertStatus(200); // out of stock

        $this->add(10)->assertStatus(200);

        $this->assertSame('10.00', $inventory->fresh()->left_quantity);
        $this->assertSame(0, InventoryAlert::openLevel()->count());
        $this->assertNotNull($this->alertsOfType('out_of_stock')->sole()->resolved_at);

        $replenished = $this->alertsOfType('stock_replenished')->sole();
        $this->assertSame('10.00', $replenished->quantity);
        $this->assertSame('manual_add', $replenished->source);

        $this->assertCount(1, $this->alertsOfType('back_in_stock'));
    }

    public function test_partial_restock_turns_out_of_stock_into_low_stock()
    {
        $this->stock(1);
        $this->assign(1)->assertStatus(200); // out of stock

        $this->add(3)->assertStatus(200); // 3 left, still under 5

        $this->assertNotNull($this->alertsOfType('out_of_stock')->sole()->resolved_at);
        $this->assertNull($this->alertsOfType('low_stock')->sole()->resolved_at);
        $this->assertCount(0, $this->alertsOfType('back_in_stock'));
    }

    public function test_adding_a_product_to_a_store_raises_stock_added()
    {
        $this->add(20)->assertStatus(200);

        $alert = $this->alertsOfType('stock_added')->sole();
        $this->assertSame('info', $alert->severity);
        $this->assertSame('20.00', $alert->quantity);
        $this->assertSame('Safety Helmet', $alert->product_name);
        $this->assertSame('Central Store', $alert->store_name);

        $this->assertSame(0, InventoryAlert::openLevel()->count());
    }

    public function test_removing_a_product_from_a_store_raises_product_removed_and_closes_its_alerts()
    {
        $inventory = $this->stock(1);
        $this->assign(1)->assertStatus(200); // out of stock, left 0

        $this->deleteJson("/api/v1/admin/inventories/{$inventory->id}")->assertStatus(200);

        $removed = $this->alertsOfType('product_removed')->sole();
        $this->assertSame($inventory->id, $removed->inventory_id);
        $this->assertSame('Safety Helmet', $removed->product_name);

        $this->assertSame(0, InventoryAlert::openLevel()->count());
    }

    public function test_raising_min_stock_flags_rows_that_are_now_under_it()
    {
        $inventory = $this->stock(8);

        $this->postJson("/api/v1/admin/products/{$this->product->id}", [
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Safety Helmet',
            'min_stock' => 10
        ])->assertStatus(200);

        $changed = $this->alertsOfType('min_stock_changed')->sole();
        // assertEquals: MySQL's JSON column does not keep key order.
        $this->assertEquals(['old_min_stock' => 5, 'new_min_stock' => 10], $changed->meta);

        $low = $this->alertsOfType('low_stock')->sole();
        $this->assertSame($inventory->id, $low->inventory_id);
        $this->assertSame('product_update', $low->source);
    }

    public function test_index_lists_newest_first_and_filters()
    {
        $this->stock(10);
        $this->assign(7)->assertStatus(200);   // low_stock
        $this->add(10)->assertStatus(200);     // stock_replenished + back_in_stock

        $response = $this->getJson('/api/v1/admin/inventory-alerts');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonPath('data.0.type', 'back_in_stock');

        $this->getJson('/api/v1/admin/inventory-alerts?type=low_stock,back_in_stock')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/admin/inventory-alerts?status=resolved')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'low_stock')
            ->assertJsonPath('data.0.is_resolved', true);

        $this->getJson('/api/v1/admin/inventory-alerts?severity=warning')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/admin/inventory-alerts?type=nonsense')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['type']]);
    }

    public function test_summary_counts_unread_alerts_and_live_stock_levels()
    {
        $this->stock(10);
        $this->assign(7)->assertStatus(200); // low_stock alert, 3 left

        // Empty since before alerts existed — no alert, but counted as stock.
        $gloves = Product::create([
            'sub_category_id' => $this->subCategory->id,
            'name' => 'Safety Gloves',
            'min_stock' => 2,
            'is_active' => 1
        ]);
        $this->stock(0, $gloves);

        $response = $this->getJson('/api/v1/admin/inventory-alerts/summary');

        $response->assertStatus(200)
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.unread_by_type.low_stock', 1)
            ->assertJsonPath('data.unread_by_type.out_of_stock', 0)
            ->assertJsonPath('data.open.low_stock', 1)
            ->assertJsonPath('data.open.out_of_stock', 0)
            ->assertJsonPath('data.stock.low_stock', 1)
            ->assertJsonPath('data.stock.out_of_stock', 1);
    }

    public function test_alerts_can_be_marked_read_one_at_a_time_or_all_at_once()
    {
        $this->add(20)->assertStatus(200);   // stock_added
        $this->assign(16)->assertStatus(200); // low_stock

        $stockAdded = $this->alertsOfType('stock_added')->sole();

        $this->postJson("/api/v1/admin/inventory-alerts/{$stockAdded->id}/read")
            ->assertStatus(200)
            ->assertJsonPath('data.is_read', true)
            ->assertJsonPath('data.read_by.id', $this->adminUser->id);

        $this->getJson('/api/v1/admin/inventory-alerts?read=unread')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'low_stock');

        $this->postJson('/api/v1/admin/inventory-alerts/read-all')
            ->assertStatus(200)
            ->assertJsonPath('data.marked', 1);

        $this->getJson('/api/v1/admin/inventory-alerts/summary')
            ->assertJsonPath('data.unread_count', 0);

        $this->getJson('/api/v1/admin/inventory-alerts/999999')->assertStatus(404);
    }
}
