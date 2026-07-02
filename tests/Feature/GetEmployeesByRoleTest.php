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

class GetEmployeesByRoleTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $supervisorRole;
    protected $siteInchargeRole;
    protected $workerRole;
    protected $driverRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Create standard roles
        $adminRole = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $this->supervisorRole = Role::create([
            'name' => 'Supervisor',
            'slug' => 'supervisor',
            'is_active' => 1
        ]);

        $this->siteInchargeRole = Role::create([
            'name' => 'Site Incharge',
            'slug' => 'site-incharge',
            'is_active' => 1
        ]);

        $this->workerRole = Role::create([
            'name' => 'Worker',
            'slug' => 'worker',
            'is_active' => 1
        ]);

        $this->driverRole = Role::create([
            'name' => 'Driver',
            'slug' => 'driver',
            'is_active' => 1
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($adminRole);

        // Authenticate
        Sanctum::actingAs($this->adminUser);
    }

    public function test_can_fetch_employees_filtered_by_supervisor_role()
    {
        // Create an employee with Supervisor role
        $supervisor = Employee::create([
            'employee_code' => 'EMP_SUP01',
            'name' => 'John Supervisor',
            'joining_date' => '2026-01-01',
            'designation_id' => $this->supervisorRole->id,
            'is_active' => 1
        ]);

        // Create an employee with Worker role
        $worker = Employee::create([
            'employee_code' => 'EMP_WRK01',
            'name' => 'Bob Worker',
            'joining_date' => '2026-01-01',
            'designation_id' => $this->workerRole->id,
            'is_active' => 1
        ]);

        $response = $this->getJson('/api/v1/employees?role=Supervisor');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment([
            'name' => 'John Supervisor',
            'employee_code' => 'EMP_SUP01'
        ]);
        $response->assertJsonMissing([
            'name' => 'Bob Worker'
        ]);
    }

    public function test_can_fetch_employees_filtered_by_site_incharge_role()
    {
        // Create an employee with Site Incharge role
        $incharge = Employee::create([
            'employee_code' => 'EMP_INC01',
            'name' => 'Alice Incharge',
            'joining_date' => '2026-01-01',
            'designation_id' => $this->siteInchargeRole->id,
            'is_active' => 1
        ]);

        // Create an employee with Supervisor role
        $supervisor = Employee::create([
            'employee_code' => 'EMP_SUP01',
            'name' => 'John Supervisor',
            'joining_date' => '2026-01-01',
            'designation_id' => $this->supervisorRole->id,
            'is_active' => 1
        ]);

        $response = $this->getJson('/api/v1/employees?role=Site Incharge');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment([
            'name' => 'Alice Incharge',
            'employee_code' => 'EMP_INC01'
        ]);
        $response->assertJsonMissing([
            'name' => 'John Supervisor'
        ]);
    }

    public function test_role_filtering_fails_with_invalid_role()
    {
        $response = $this->getJson('/api/v1/employees?role=Worker');

        // Worker is not in the allowed enum (Supervisor, Site Incharge, Driver)
        $response->assertStatus(422);
    }

    public function test_can_fetch_employees_filtered_by_driver_role()
    {
        // Create an employee with Driver role
        $driver = Employee::create([
            'employee_code' => 'EMP_DRV01',
            'name' => 'Dave Driver',
            'joining_date' => '2026-01-01',
            'designation_id' => $this->driverRole->id,
            'is_active' => 1
        ]);

        // Create an employee with Supervisor role
        $supervisor = Employee::create([
            'employee_code' => 'EMP_SUP01',
            'name' => 'John Supervisor',
            'joining_date' => '2026-01-01',
            'designation_id' => $this->supervisorRole->id,
            'is_active' => 1
        ]);

        $response = $this->getJson('/api/v1/employees?role=Driver');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonFragment([
            'name' => 'Dave Driver',
            'employee_code' => 'EMP_DRV01'
        ]);
        $response->assertJsonMissing([
            'name' => 'John Supervisor'
        ]);
    }
}
