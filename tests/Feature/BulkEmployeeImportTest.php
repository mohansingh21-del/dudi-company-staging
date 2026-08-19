<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\Role;
use App\Models\Relay;
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

    /**
     * The legacy relay_1/2/3 alias map used to rewrite the sheet's value before
     * the lookup, so a site whose relays are actually named Relay_1/2/3 had
     * every one of them rejected as "not found".
     *
     * @test
     */
    public function it_matches_a_relay_named_with_the_legacy_spelling()
    {
        $relay = Relay::create(['name' => 'Relay_2', 'is_active' => 1]);

        $rows = collect([
            [
                'employee_code' => 'EMP_BULK_02',
                'name' => 'Relay Employee',
                'joining_date' => '21/07/2026',
                'relay' => 'Relay_2',
            ]
        ]);

        (new EmployeeImport())->collection($rows);

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'EMP_BULK_02',
            'relay_id' => $relay->id,
        ]);
    }

    /** @test */
    public function it_matches_a_relay_name_ignoring_case_and_padding()
    {
        $relay = Relay::create(['name' => 'relay 4', 'is_active' => 1]);

        $rows = collect([
            [
                'employee_code' => 'EMP_BULK_03',
                'name' => 'Case Employee',
                'joining_date' => '21/07/2026',
                'relay' => ' Relay 4 ',
            ]
        ]);

        (new EmployeeImport())->collection($rows);

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'EMP_BULK_03',
            'relay_id' => $relay->id,
        ]);
    }

    /**
     * Sheets written against the old spelling must keep working where the
     * relays really are named Relay A/B/C.
     *
     * @test
     */
    public function it_still_falls_back_to_the_legacy_alias()
    {
        $relay = Relay::create(['name' => 'Relay B', 'is_active' => 1]);

        $rows = collect([
            [
                'employee_code' => 'EMP_BULK_04',
                'name' => 'Legacy Employee',
                'joining_date' => '21/07/2026',
                'relay' => 'relay_2',
            ]
        ]);

        (new EmployeeImport())->collection($rows);

        $this->assertDatabaseHas('employees', [
            'employee_code' => 'EMP_BULK_04',
            'relay_id' => $relay->id,
        ]);
    }
}
