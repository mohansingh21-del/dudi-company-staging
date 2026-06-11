<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActiveEmployeesTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $department1;
    protected $site1;

    protected function setUp(): void
    {
        parent::setUp();

        // Create standard role and user
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

        // Create department and site
        $this->department1 = Department::create([
            'code' => 'DEP01',
            'name' => 'Mining',
            'is_active' => 1
        ]);

        $this->site1 = Site::create([
            'site_name' => 'Main Site',
            'address' => '123 Site St',
            'is_active' => 1
        ]);
    }

    public function test_can_fetch_only_active_employees()
    {
        // Active employee
        Employee::create([
            'employee_code' => 'EMP001',
            'name' => 'Active Worker',
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);

        // Inactive employee
        Employee::create([
            'employee_code' => 'EMP002',
            'name' => 'Inactive Worker',
            'joining_date' => '2026-01-01',
            'is_active' => 0
        ]);

        $response = $this->getJson('/api/v1/active-employees');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment([
            'name' => 'Active Worker',
            'employee_code' => 'EMP001'
        ]);
        $response->assertJsonMissing([
            'name' => 'Inactive Worker'
        ]);
    }

    public function test_can_filter_and_search_active_employees()
    {
        Employee::create([
            'employee_code' => 'EMP001',
            'name' => 'Ravi Verma',
            'joining_date' => '2026-01-01',
            'department_id' => $this->department1->id,
            'is_active' => 1
        ]);

        Employee::create([
            'employee_code' => 'EMP002',
            'name' => 'Suman Sharma',
            'joining_date' => '2026-01-01',
            'site_id' => $this->site1->id,
            'is_active' => 1
        ]);

        // Search test
        $response = $this->getJson('/api/v1/active-employees?search=Ravi');
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Ravi Verma']);

        // Department filter test
        $response = $this->getJson("/api/v1/active-employees?department_id={$this->department1->id}");
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Ravi Verma']);

        // Site filter test
        $response = $this->getJson("/api/v1/active-employees?site_id={$this->site1->id}");
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment(['name' => 'Suman Sharma']);
    }

    public function test_can_paginate_active_employees()
    {
        Employee::create([
            'employee_code' => 'EMP001',
            'name' => 'Ravi Verma',
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);

        Employee::create([
            'employee_code' => 'EMP002',
            'name' => 'Suman Sharma',
            'joining_date' => '2026-01-01',
            'is_active' => 1
        ]);

        $response = $this->getJson('/api/v1/active-employees?limit=1');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonStructure([
            'status',
            'message',
            'data',
            'pagination' => [
                'current_page',
                'last_page',
                'per_page',
                'total',
                'from',
                'to'
            ]
        ]);
    }
}
