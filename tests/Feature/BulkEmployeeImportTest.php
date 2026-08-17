<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Imports\EmployeeImport;
use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class BulkEmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

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

        Sanctum::actingAs($this->adminUser);
    }

    /** @test */
    public function it_imports_employees_via_bulk_upload()
    {
        $rows = collect([
            [
                'employee_code' => 'EMP_BULK_01',
                'name' => 'Test Employee',
                'joining_date' => '21/07/2026',
                'dob' => '01/01/1995',
                'gender' => 'male',
                'mobile' => '9876543210',
            ]
        ]);

        $import = new EmployeeImport();
        $import->collection($rows);

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'EMP_BULK_01',
            'name' => 'Test Employee',
        ]);
    }
}
