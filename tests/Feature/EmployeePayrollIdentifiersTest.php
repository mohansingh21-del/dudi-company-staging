<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeePayroll;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Statutory identifiers (PAN, Aadhaar, UAN, ESIC IP, LWF) live on the salary
 * record alongside the bank details, not on the employee.
 */
class EmployeePayrollIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1,
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
        ]);
        $this->adminUser->roles()->attach($role);

        Sanctum::actingAs($this->adminUser);

        $this->employee = Employee::create([
            'employee_code' => 'EMP7001',
            'name' => 'Suresh',
            'surname' => 'Yadav',
            'joining_date' => '2026-04-01',
            'is_active' => 1,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $this->employee->id,
            'salary_type' => 'monthly',
            'basic_salary' => 25000,
            'pf_applicable' => true,
            'pf_number' => 'PF/001',
            'uan' => '100200300400',
            'esic_ip_number' => '3113456789',
            'lwf_number' => 'LWF/2026/12',
            'pan' => 'ABCDE1234F',
            'aadhaar_number' => '444455556666',
            'bank_name' => 'SBI',
            'bank_account_number' => '00112233445',
            'ifsc_code' => 'SBIN0001234',
        ], $overrides);
    }

    public function test_can_store_statutory_identifiers_on_the_salary_record()
    {
        $this->postJson('/api/v1/admin/employee-payrolls', $this->payload())
            ->assertStatus(200);

        $payroll = EmployeePayroll::where('employee_id', $this->employee->id)->first();

        $this->assertEquals('100200300400', $payroll->uan);
        $this->assertEquals('3113456789', $payroll->esic_ip_number);
        $this->assertEquals('LWF/2026/12', $payroll->lwf_number);
        $this->assertEquals('ABCDE1234F', $payroll->pan);
        $this->assertEquals('444455556666', $payroll->aadhaar_number);
        $this->assertEquals('6666', $payroll->aadhaar_last4);
    }

    public function test_aadhaar_is_encrypted_at_rest_but_returned_in_full()
    {
        $this->postJson('/api/v1/admin/employee-payrolls', $this->payload())
            ->assertStatus(200);

        $payroll = EmployeePayroll::where('employee_id', $this->employee->id)->first();

        $this->assertNotEquals(
            '444455556666',
            DB::table('employee_payrolls')->where('id', $payroll->id)->value('aadhaar_number')
        );

        $response = $this->getJson("/api/v1/admin/employee-payrolls/{$payroll->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('data.aadhaar_last4', '444455556666');
    }

    public function test_employee_response_carries_no_pay_details()
    {
        $this->postJson('/api/v1/admin/employee-payrolls', $this->payload())->assertStatus(200);

        $response = $this->getJson("/api/v1/admin/employees/{$this->employee->id}");
        $response->assertStatus(200);

        $keys = array_keys($response->json('data'));

        foreach ([
            'pan', 'aadhaar_last4', 'aadhaar_number', 'uan', 'esic_ip_number', 'lwf_number',
            'salary_type', 'basic_salary', 'daily_wage', 'pf_applicable', 'pf_number',
            'pf_amount', 'bank_name', 'bank_account_number', 'ifsc_code',
            'mess_deduction_applicable', 'mess_deduction_amount',
            'other_deduction_appliacble', 'other_deduction', 'rest_days',
        ] as $payKey) {
            $this->assertNotContains($payKey, $keys, "Employee response must not expose '{$payKey}'.");
        }
    }

    public function test_duplicate_aadhaar_is_rejected()
    {
        $this->postJson('/api/v1/admin/employee-payrolls', $this->payload())->assertStatus(200);

        $other = Employee::create([
            'employee_code' => 'EMP7002',
            'name' => 'Ramesh',
            'joining_date' => '2026-04-01',
            'is_active' => 1,
        ]);

        $this->postJson('/api/v1/admin/employee-payrolls', $this->payload([
            'employee_id' => $other->id,
            'pan' => 'ZZZZZ9999Z',
            'uan' => '900800700600',
        ]))->assertStatus(422)->assertJsonValidationErrors('aadhaar_number');
    }

    public function test_pan_is_uppercased_and_format_enforced()
    {
        $this->postJson('/api/v1/admin/employee-payrolls', $this->payload([
            'pan' => 'abcde1234f',
        ]))->assertStatus(200);

        $this->assertEquals(
            'ABCDE1234F',
            EmployeePayroll::where('employee_id', $this->employee->id)->value('pan')
        );

        $other = Employee::create([
            'employee_code' => 'EMP7003',
            'name' => 'Mohan',
            'joining_date' => '2026-04-01',
            'is_active' => 1,
        ]);

        $this->postJson('/api/v1/admin/employee-payrolls', $this->payload([
            'employee_id' => $other->id,
            'pan' => 'INVALID123',
            'aadhaar_number' => '111122223333',
            'uan' => '900800700600',
        ]))->assertStatus(422)->assertJsonValidationErrors('pan');
    }

    public function test_aadhaar_separators_are_stripped()
    {
        $this->postJson('/api/v1/admin/employee-payrolls', $this->payload([
            'aadhaar_number' => '4444 5555 6666',
        ]))->assertStatus(200);

        $payroll = EmployeePayroll::where('employee_id', $this->employee->id)->first();

        $this->assertEquals('444455556666', $payroll->aadhaar_number);
        $this->assertEquals('6666', $payroll->aadhaar_last4);
    }

    public function test_identifiers_can_be_updated()
    {
        $this->postJson('/api/v1/admin/employee-payrolls', $this->payload())->assertStatus(200);

        $payroll = EmployeePayroll::where('employee_id', $this->employee->id)->first();

        $this->putJson("/api/v1/admin/employee-payrolls/{$payroll->id}", [
            'salary_type' => 'monthly',
            'basic_salary' => 26000,
            'pan' => 'PQRST5678K',
            'aadhaar_number' => '777788889999',
            'uan' => '111222333444',
        ])->assertStatus(200);

        $payroll->refresh();

        $this->assertEquals('PQRST5678K', $payroll->pan);
        $this->assertEquals('777788889999', $payroll->aadhaar_number);
        $this->assertEquals('9999', $payroll->aadhaar_last4);
        $this->assertEquals('111222333444', $payroll->uan);
    }

    public function test_updating_own_record_does_not_trip_uniqueness()
    {
        $this->postJson('/api/v1/admin/employee-payrolls', $this->payload())->assertStatus(200);

        $payroll = EmployeePayroll::where('employee_id', $this->employee->id)->first();

        $this->putJson("/api/v1/admin/employee-payrolls/{$payroll->id}", [
            'salary_type' => 'monthly',
            'basic_salary' => 27000,
            'pan' => 'ABCDE1234F',
            'aadhaar_number' => '444455556666',
            'uan' => '100200300400',
        ])->assertStatus(200);
    }
}
