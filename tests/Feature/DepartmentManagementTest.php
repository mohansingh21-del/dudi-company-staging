<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
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

    public function test_can_list_public_departments()
    {
        Department::create([
            'code' => 'DEP01',
            'name' => 'Active Department',
            'is_active' => 1
        ]);

        Department::create([
            'code' => 'DEP02',
            'name' => 'Inactive Department',
            'is_active' => 0
        ]);

        $response = $this->getJson('/api/v1/departments');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'name' => 'Active Department',
                'status' => 1
            ]);
    }

    public function test_can_filter_public_employees_by_department_id()
    {
        $dep1 = Department::create([
            'code' => 'DEP01',
            'name' => 'Department One',
            'is_active' => 1
        ]);

        $dep2 = Department::create([
            'code' => 'DEP02',
            'name' => 'Department Two',
            'is_active' => 1
        ]);

        Employee::create([
            'employee_code' => 'EMP001',
            'name' => 'Employee in Dep 1',
            'department_id' => $dep1->id,
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);

        Employee::create([
            'employee_code' => 'EMP002',
            'name' => 'Employee in Dep 2',
            'department_id' => $dep2->id,
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);

        // Filter by Department 1
        $response = $this->getJson('/api/v1/employees?department_id=' . $dep1->id);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'name' => 'Employee in Dep 1',
                'employee_code' => 'EMP001'
            ])
            ->assertJsonMissing([
                'name' => 'Employee in Dep 2',
                'employee_code' => 'EMP002'
            ]);
    }
}
