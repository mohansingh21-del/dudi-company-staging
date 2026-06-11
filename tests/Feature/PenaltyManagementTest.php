<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Penalty;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PenaltyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $employee1;
    protected $employee2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create super-admin role and user
        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($role);

        // Authenticate
        Sanctum::actingAs($this->adminUser);

        // Create test employees
        $this->employee1 = Employee::create([
            'employee_code' => 'EMP001',
            'name' => 'Ravi Verma',
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);

        $this->employee2 = Employee::create([
            'employee_code' => 'EMP002',
            'name' => 'Suman Sharma',
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);
    }

    public function test_can_list_penalties_with_pagination_and_filters()
    {
        // Create sample penalties
        Penalty::create([
            'employee_id' => $this->employee1->id,
            'penalty_date' => '2026-06-01',
            'month' => 6,
            'year' => 2026,
            'reason' => 'Late arrival',
            'amount' => 150.00
        ]);

        Penalty::create([
            'employee_id' => $this->employee2->id,
            'penalty_date' => '2026-06-01',
            'month' => 6,
            'year' => 2026,
            'reason' => 'Equipment damage',
            'amount' => 1000.00
        ]);

        // 1. Simple index listing
        $response = $this->getJson('/api/v1/admin/penalties');
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');

        // 2. Search filter (by reason or employee name)
        $response = $this->getJson('/api/v1/admin/penalties?search=Equipment');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['reason' => 'Equipment damage']);

        // 3. Employee ID filter
        $response = $this->getJson("/api/v1/admin/penalties?employee_id={$this->employee1->id}");
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['employee_name' => 'Ravi Verma']);

        // 4. Month/Year filter
        $response = $this->getJson('/api/v1/admin/penalties?month=6&year=2026');
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_can_create_single_penalty()
    {
        $response = $this->postJson('/api/v1/admin/penalties', [
            'employee_id' => $this->employee1->id,
            'penalty_date' => '2026-06-11',
            'reason' => 'Uninformed leave',
            'amount' => 500.50
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Penalty applied successfully',
            'employee_name' => 'Ravi Verma',
            'reason' => 'Uninformed leave',
            'amount' => '500.50'
        ]);

        $this->assertDatabaseHas('penalties', [
            'employee_id' => $this->employee1->id,
            'month' => 6,
            'year' => 2026,
            'reason' => 'Uninformed leave',
            'amount' => 500.50
        ]);
    }

    public function test_single_penalty_creation_validation_fails_on_invalid_input()
    {
        // Test missing fields
        $response = $this->postJson('/api/v1/admin/penalties', []);
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Validation failed']);

        // Test invalid amount & invalid penalty date format
        $response = $this->postJson('/api/v1/admin/penalties', [
            'employee_id' => $this->employee1->id,
            'penalty_date' => 'invalid-date-string',
            'reason' => 'Late arrival',
            'amount' => -100
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['penalty_date', 'amount']);
    }

    public function test_can_create_bulk_penalties()
    {
        $response = $this->postJson('/api/v1/admin/penalties/bulk', [
            'employee_ids' => [$this->employee1->id, $this->employee2->id],
            'penalty_date' => '2026-06-11',
            'reason' => 'Safety violation',
            'amount' => 300.00
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => '2 penalties applied successfully'
        ]);

        $this->assertDatabaseHas('penalties', [
            'employee_id' => $this->employee1->id,
            'month' => 6,
            'year' => 2026,
            'reason' => 'Safety violation',
            'amount' => 300.00
        ]);

        $this->assertDatabaseHas('penalties', [
            'employee_id' => $this->employee2->id,
            'month' => 6,
            'year' => 2026,
            'reason' => 'Safety violation',
            'amount' => 300.00
        ]);
    }

    public function test_bulk_penalty_creation_validation_fails_on_invalid_input()
    {
        $response = $this->postJson('/api/v1/admin/penalties/bulk', [
            'employee_ids' => [99999], // non-existent employee
            'penalty_date' => '2026-06-11',
            'reason' => 'Safety violation',
            'amount' => 300.00
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['employee_ids.0']);
    }

    public function test_can_show_penalty()
    {
        $penalty = Penalty::create([
            'employee_id' => $this->employee1->id,
            'penalty_date' => '2026-06-01',
            'month' => 6,
            'year' => 2026,
            'reason' => 'Late arrival',
            'amount' => 150.00
        ]);

        $response = $this->getJson("/api/v1/admin/penalties/{$penalty->id}");
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'reason' => 'Late arrival',
            'amount' => '150.00'
        ]);

        // Test non-existent penalty details
        $response = $this->getJson('/api/v1/admin/penalties/99999');
        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Penalty not found']);
    }

    public function test_can_update_penalty()
    {
        $penalty = Penalty::create([
            'employee_id' => $this->employee1->id,
            'penalty_date' => '2026-06-01',
            'month' => 6,
            'year' => 2026,
            'reason' => 'Late arrival',
            'amount' => 150.00
        ]);

        $response = $this->putJson("/api/v1/admin/penalties/{$penalty->id}", [
            'reason' => 'Late arrival (updated)',
            'amount' => 200.00
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Penalty updated successfully',
            'reason' => 'Late arrival (updated)',
            'amount' => '200.00'
        ]);

        $this->assertDatabaseHas('penalties', [
            'id' => $penalty->id,
            'reason' => 'Late arrival (updated)',
            'amount' => 200.00
        ]);
    }

    public function test_can_delete_penalty()
    {
        $penalty = Penalty::create([
            'employee_id' => $this->employee1->id,
            'penalty_date' => '2026-06-01',
            'month' => 6,
            'year' => 2026,
            'reason' => 'Late arrival',
            'amount' => 150.00
        ]);

        $response = $this->deleteJson("/api/v1/admin/penalties/{$penalty->id}");
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Penalty deleted successfully'
        ]);

        $this->assertDatabaseMissing('penalties', [
            'id' => $penalty->id
        ]);
    }

    public function test_can_upload_penalties_in_bulk_via_excel()
    {
        \Maatwebsite\Excel\Facades\Excel::fake();

        $file = \Illuminate\Http\UploadedFile::fake()->create('penalties.xlsx');

        $response = $this->postJson('/api/v1/admin/penalties/bulk-upload', [
            'file' => $file
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Penalties imported successfully'
        ]);

        \Maatwebsite\Excel\Facades\Excel::assertImported('penalties.xlsx');
    }

    public function test_real_excel_import_with_actual_values()
    {
        // Ensure employees exist
        Employee::create([
            'employee_code' => '102',
            'name' => 'Sanjay Kumar',
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);
        Employee::create([
            'employee_code' => 'EMP0044',
            'name' => 'Amit Kumar',
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);

        // Create a temporary Excel/CSV file with the exact structure
        $csvContent = "employee_code,penalty_date,reason,amount\n";
        $csvContent .= "102,2026-06-11,bs es hi kr deya,430\n";
        $csvContent .= "EMP0044,2026-06-11,pese to ktege mitr,501\n";

        $tempFilePath = tempnam(sys_get_temp_dir(), 'import_test_') . '.csv';
        file_put_contents($tempFilePath, $csvContent);

        $file = new \Illuminate\Http\UploadedFile(
            $tempFilePath,
            'import_test.csv',
            'text/csv',
            null,
            true
        );

        $response = $this->postJson('/api/v1/admin/penalties/bulk-upload', [
            'file' => $file
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => 'Penalties imported successfully'
        ]);

        $this->assertDatabaseHas('penalties', [
            'employee_id' => Employee::where('employee_code', '102')->first()->id,
            'amount' => 430.00,
            'reason' => 'bs es hi kr deya'
        ]);

        $this->assertDatabaseHas('penalties', [
            'employee_id' => Employee::where('employee_code', 'EMP0044')->first()->id,
            'amount' => 501.00,
            'reason' => 'pese to ktege mitr'
        ]);

        @unlink($tempFilePath);
    }
}
