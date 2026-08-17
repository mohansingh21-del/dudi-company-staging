<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the Employee Register (Form A) fields on the registration API.
 *
 * Registration is identity-only. Statutory identifiers (PAN, Aadhaar, UAN,
 * ESIC IP, LWF) and bank details belong to the salary record — see
 * EmployeePayrollIdentifiersTest.
 */
class EmployeeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1,
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
        ]);
        $this->adminUser->roles()->attach($this->role);

        Sanctum::actingAs($this->adminUser);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'employee_code' => 'EMP9001',
            'name' => 'Suresh',
            'surname' => 'Yadav',
            'father_name' => 'Ram Yadav',
            'dob' => '15/08/1990',
            'gender' => 'male',
            'nationality' => 'Indian',
            'education_level' => 'ITI',
            'identification_mark' => 'Scar on left hand',
            'mobile' => '9876500001',
            'address' => 'Quarter 12, Colony',
            'permanent_address' => 'Village Dudi',
            'joining_date' => '01/04/2026',
            'service_book_no' => 'SB/2026/44',
            'employee_type' => 'contract',
            'skill_category' => 'semi_skilled',
            'designation_id' => $this->role->id,
            'remarks' => 'Register entry verified',
            'status' => 1,
        ], $overrides);
    }

    public function test_can_register_employee_with_register_fields()
    {
        $response = $this->postJson('/api/v1/admin/employees', $this->payload());

        $response->assertStatus(200);

        $employee = Employee::where('employee_code', 'EMP9001')->first();

        $this->assertNotNull($employee);
        $this->assertEquals('Suresh', $employee->name);
        $this->assertEquals('Yadav', $employee->surname);
        $this->assertEquals('Suresh Yadav', $employee->full_name);
        $this->assertEquals('Indian', $employee->nationality);
        $this->assertEquals('ITI', $employee->education_level);
        $this->assertEquals('contract', $employee->employee_type);
        $this->assertEquals('SB/2026/44', $employee->service_book_no);
        $this->assertEquals('semi_skilled', $employee->skill_category);
        $this->assertEquals('Semi-Skilled', $employee->skill_category_label);
        $this->assertEquals('1990-08-15', $employee->dob->format('Y-m-d'));
        $this->assertEquals('2026-04-01', $employee->joining_date->format('Y-m-d'));
        $this->assertTrue($employee->is_active);
    }

    public function test_date_of_exit_deactivates_the_employee()
    {
        $this->postJson('/api/v1/admin/employees', $this->payload())->assertStatus(200);
        $employee = Employee::where('employee_code', 'EMP9001')->first();

        $this->putJson("/api/v1/admin/employees/{$employee->id}", [
            'name' => 'Suresh',
            'surname' => 'Yadav',
            'joining_date' => '01/04/2026',
            'date_of_exit' => '30/06/2026',
            'reason_for_exit' => 'Resigned',
            'status' => 1,
        ])->assertStatus(200);

        $employee->refresh();

        $this->assertEquals('2026-06-30', $employee->date_of_exit->format('Y-m-d'));
        $this->assertEquals('Resigned', $employee->reason_for_exit);
        $this->assertFalse($employee->is_active, 'An employee with a date of exit must not stay active.');
    }

    public function test_exit_date_cannot_precede_joining_date()
    {
        $this->postJson('/api/v1/admin/employees', $this->payload([
            'date_of_exit' => '01/01/2026',
            'reason_for_exit' => 'Resigned',
        ]))->assertStatus(422)->assertJsonValidationErrors('date_of_exit');
    }

    public function test_can_upload_photo_and_signature()
    {
        Storage::fake('public');

        $response = $this->postJson('/api/v1/admin/employees', $this->payload([
            'photo' => UploadedFile::fake()->image('photo.jpg'),
            'signature' => UploadedFile::fake()->image('sign.png'),
        ]));

        $response->assertStatus(200);

        $employee = Employee::where('employee_code', 'EMP9001')->first();

        $this->assertNotNull($employee->photo_path);
        $this->assertNotNull($employee->signature_path);
        Storage::disk('public')->assertExists($employee->photo_path);
        Storage::disk('public')->assertExists($employee->signature_path);

        // Replacing the photo removes the file it supersedes.
        $oldPhoto = $employee->photo_path;

        $this->putJson("/api/v1/admin/employees/{$employee->id}", [
            'name' => 'Suresh',
            'joining_date' => '01/04/2026',
            'photo' => UploadedFile::fake()->image('new-photo.jpg'),
        ])->assertStatus(200);

        $employee->refresh();

        $this->assertNotEquals($oldPhoto, $employee->photo_path);
        Storage::disk('public')->assertMissing($oldPhoto);
        Storage::disk('public')->assertExists($employee->photo_path);
    }

    public function test_status_input_maps_to_is_active()
    {
        $this->postJson('/api/v1/admin/employees', $this->payload(['status' => 0]))
            ->assertStatus(200);

        $this->assertFalse(Employee::where('employee_code', 'EMP9001')->first()->is_active);
    }

    public function test_skill_category_is_restricted_to_the_four_values()
    {
        $this->postJson('/api/v1/admin/employees', $this->payload([
            'skill_category' => 'super_skilled',
        ]))->assertStatus(422)->assertJsonValidationErrors('skill_category');

        foreach (['highly_skilled', 'skilled', 'semi_skilled', 'unskilled'] as $i => $value) {
            $this->postJson('/api/v1/admin/employees', $this->payload([
                'employee_code' => 'EMPSK' . $i,
                'mobile' => '98765100' . $i,
                'skill_category' => $value,
            ]))->assertStatus(200);
        }

        $this->assertEquals(4, Employee::whereNotNull('skill_category')->count());
    }

    public function test_employee_type_is_restricted_to_register_values()
    {
        $this->postJson('/api/v1/admin/employees', $this->payload([
            'employee_type' => 'daily_wage',
        ]))->assertStatus(422)->assertJsonValidationErrors('employee_type');
    }

    public function test_registration_does_not_accept_pay_identifiers()
    {
        $this->postJson('/api/v1/admin/employees', $this->payload([
            'pan' => 'ABCDE1234F',
            'aadhaar_number' => '444455556666',
            'uan' => '123456789012',
            'bank_account_number' => '00112233',
        ]))->assertStatus(200);

        $employee = Employee::where('employee_code', 'EMP9001')->first();

        // Silently ignored — these belong to the salary record.
        $this->assertNull($employee->activePayroll);
        $this->assertArrayNotHasKey('pan', $employee->getAttributes());
        $this->assertArrayNotHasKey('aadhaar_number', $employee->getAttributes());
    }
}
