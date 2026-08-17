<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Payroll;
use App\Models\AttendanceProcessed;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Penalty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayrollManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $employee;
    protected $role;

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

        // Create designation role for employee
        $this->role = Role::create([
            'name' => 'Worker',
            'slug' => 'worker',
            'is_active' => 1
        ]);

        // Create designation in designations table with the exact same ID to satisfy DB foreign key constraint
        \Illuminate\Support\Facades\DB::table('designations')->insert([
            'id' => $this->role->id,
            'designation_name' => 'Worker',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test employee
        $this->employee = Employee::create([
            'employee_code' => 'EMP5001',
            'name' => 'Amit Sharma',
            'joining_date' => '2026-01-01',
            'is_active' => 1,
            'designation_id' => $this->role->id,
        ]);

        // Pay details live on the salary record, not the employee row.
        \App\Models\EmployeePayroll::create([
            'employee_id' => $this->employee->id,
            'salary_type' => 'monthly',
            'basic_salary' => 25000.00,
            'pf_applicable' => 1,
            'pf_amount' => 1200.00,
            'mess_deduction_applicable' => 1,
            'mess_deduction_amount' => 500.00,
            'is_active' => 1,
        ]);
    }

    public function test_can_list_payroll_with_on_the_fly_calculations()
    {
        // 1 present day in June 2026 (30 days total)
        AttendanceProcessed::create([
            'employee_id' => $this->employee->id,
            'date' => '2026-06-01',
            'attendance_status' => 'present'
        ]);

        // 29 absent days
        for ($i = 2; $i <= 30; $i++) {
            AttendanceProcessed::create([
                'employee_id' => $this->employee->id,
                'date' => sprintf('2026-06-%02d', $i),
                'attendance_status' => 'absent'
            ]);
        }

        // 1 penalty in June 2026
        Penalty::create([
            'employee_id' => $this->employee->id,
            'penalty_date' => '2026-06-02',
            'month' => 6,
            'year' => 2026,
            'reason' => 'Late',
            'amount' => 2000.00
        ]);

        // Earnings: Basic (25000) + Allowance (0) + Incentives (0) = 25000 gross
        // Per day salary: 25000 / 30 = 833.3333
        // Days worked: 1 present. Holidays = 0. Rest days = 0. Paid leaves = 0.
        // Effective absent: 30 - 1 = 29 days absent (unmarked count as absent)
        // Leave deduction: round(29 * (25000 / 30)) = 24167.
        // PF (1200) + Mess (500) + Penalty (2000) = 3700 other deductions
        // Total deductions = 24167 + 3700 = 27867
        // Net salary = max(0, 25000 - 27867) = 0.00

        $response = $this->getJson('/api/v1/admin/payroll?month=6&year=2026');
        $response->assertStatus(200);

        $response->assertJsonFragment([
            'employee_code' => 'EMP5001',
            'basic_salary' => 25000,
            'shift_allowance' => 0,
            'incentives' => 0,
            'gross_salary' => 25000,
            'present_days' => 1,
            'absent_days' => 29,
            'payable_days' => 1,
            'net_salary' => 0
        ]);
    }

    public function test_can_generate_payroll_records()
    {
        // Add 20 present days, 4 rest days, 2 holidays (site or general)
        for ($i = 1; $i <= 20; $i++) {
            AttendanceProcessed::create([
                'employee_id' => $this->employee->id,
                'date' => sprintf('2026-06-%02d', $i),
                'attendance_status' => 'present'
            ]);
        }

        // Generate endpoint
        $response = $this->postJson('/api/v1/admin/payroll/generate', [
            'month' => 6,
            'year' => 2026,
            'employee_id' => $this->employee->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => '1 payroll record(s) generated successfully'
        ]);

        $this->assertDatabaseHas('payrolls', [
            'employee_id' => $this->employee->id,
            'month' => 6,
            'year' => 2026,
            'status' => 'generated'
        ]);
    }

    public function test_can_show_payroll_details()
    {
        $response = $this->getJson("/api/v1/admin/payroll/{$this->employee->id}/detail?month=6&year=2026");
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'employee',
                'payroll_period',
                'attendance',
                'earnings',
                'deductions',
                'penalties',
                'net_salary',
                'payroll_record'
            ]
        ]);
    }

    public function test_can_update_payroll_status_and_bulk_update()
    {
        // First generate a payroll
        $this->postJson('/api/v1/admin/payroll/generate', [
            'month' => 6,
            'year' => 2026,
            'employee_id' => $this->employee->id
        ]);

        $payroll = Payroll::first();

        // Update status to paid
        $response = $this->patchJson("/api/v1/admin/payroll/{$payroll->id}/status", [
            'status' => 'paid'
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('payrolls', [
            'id' => $payroll->id,
            'status' => 'paid'
        ]);

        // Bulk status back to draft
        $response = $this->patchJson('/api/v1/admin/payroll/bulk-status', [
            'payroll_ids' => [$payroll->id],
            'status' => 'draft'
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('payrolls', [
            'id' => $payroll->id,
            'status' => 'draft'
        ]);
    }

    public function test_can_delete_payroll_record()
    {
        // First generate a payroll
        $this->postJson('/api/v1/admin/payroll/generate', [
            'month' => 6,
            'year' => 2026,
            'employee_id' => $this->employee->id
        ]);

        $payroll = Payroll::first();

        $response = $this->deleteJson("/api/v1/admin/payroll/{$payroll->id}");
        $response->assertStatus(200);
        $this->assertDatabaseMissing('payrolls', [
            'id' => $payroll->id
        ]);
    }

    public function test_can_get_penalty_details_for_payroll_row()
    {
        // Create a penalty for June 2026
        Penalty::create([
            'employee_id' => $this->employee->id,
            'penalty_date' => '2026-06-12',
            'month' => 6,
            'year' => 2026,
            'reason' => 'Uninformed absence from shift',
            'amount' => 500.00
        ]);

        $response = $this->getJson("/api/v1/admin/payroll/{$this->employee->id}/penalties?month=6&year=2026");
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'total_penalty' => 500,
            'month_name' => 'Jun 2026',
        ]);
        $response->assertJsonStructure([
            'data' => [
                'employee' => [
                    'id',
                    'name',
                    'employee_code',
                ],
                'total_penalty',
                'month_name',
                'penalties' => [
                    '*' => [
                        'id',
                        'date',
                        'reason',
                        'amount',
                    ]
                ]
            ]
        ]);
    }

    public function test_rest_days_do_not_offset_unpaid_leaves()
    {
        // For June 2026 (30 days)
        // Employee basic_salary = 25000.
        // Gross Salary = 25000. Per day salary = 25000 / 30 = 833.33.
        // Let's create:
        // - 18 present days
        // - 5 rest days
        // - 7 unpaid leaves (so 18 + 5 + 7 = 30 days total)
        // Expected outcome:
        // - Rest days do not offset unpaid leaves.
        // - So all 7 unpaid leaves remain and are deducted.
        // - Effective absent days = 7.
        // - Leave deduction = round(7 * 833.33) = 5833.

        // 18 present days
        for ($i = 1; $i <= 18; $i++) {
            AttendanceProcessed::create([
                'employee_id' => $this->employee->id,
                'date' => sprintf('2026-06-%02d', $i),
                'attendance_status' => 'present'
            ]);
        }

        // 5 rest days
        for ($i = 19; $i <= 23; $i++) {
            AttendanceProcessed::create([
                'employee_id' => $this->employee->id,
                'date' => sprintf('2026-06-%02d', $i),
                'attendance_status' => 'rest_day'
            ]);
        }

        // 7 unpaid leaves
        $leaveType = LeaveType::create([
            'name' => 'Unpaid Leave',
            'leave_category' => 'unpaid',
            'allowed_days' => 10,
            'is_active' => 1
        ]);

        Leave::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => '2026-06-24',
            'to_date' => '2026-06-30',
            'reason' => 'Personal work',
            'status' => 'approved'
        ]);

        // Disable PF and Mess deductions for clean net salary verification, set rest_days setting
        $this->employee->activePayroll->update([
            'pf_applicable' => 0,
            'mess_deduction_applicable' => 0,
            'rest_days' => 5,
        ]);

        // 1. Fetch payroll data (on-the-fly calculation)
        $response = $this->getJson('/api/v1/admin/payroll?month=6&year=2026');
        $response->assertStatus(200);

        // Expected paid_leave_days: 0 (rest days are separate)
        // Expected unpaid_leave_days: 7
        // Expected absent_days (effective absent): 7
        // Expected net_salary: Gross (25000) - Leave Deduction (5833) = 19167
        $response->assertJsonFragment([
            'employee_code' => 'EMP5001',
            'present_days' => 18,
            'rest_days' => '5/5',
            'paid_leave_days' => 0,
            'unpaid_leave_days' => 7,
            'absent_days' => 0,
            'payable_days' => 23,
            'leave_deduction' => 5833,
            'net_salary' => 19167
        ]);

        // 2. Generate payroll and verify database record
        $genResponse = $this->postJson('/api/v1/admin/payroll/generate', [
            'month' => 6,
            'year' => 2026,
            'employee_id' => $this->employee->id
        ]);
        $genResponse->assertStatus(200);

        $this->assertDatabaseHas('payrolls', [
            'employee_id' => $this->employee->id,
            'month' => 6,
            'year' => 2026,
            'present_days' => 18,
            'absent_days' => 0,
            'paid_leave_days' => 5,
            'unpaid_leave_days' => 7,
            'leave_deduction' => 5833,
            'net_salary' => 19167
        ]);
    }

    public function test_can_bulk_generate_payroll_records()
    {
        // Create another employee
        $employee2 = Employee::create([
            'employee_code' => 'EMP5002',
            'name' => 'John Doe',
            'designation_id' => $this->role->id,
            'joining_date' => '2026-01-01',
            'salary_type' => 'monthly',
            'basic_salary' => 20000,
            'pf_applicable' => 0,
            'mess_deduction_applicable' => 0,
            'is_active' => 1
        ]);

        $response = $this->postJson('/api/v1/admin/payroll/generate', [
            'month' => 6,
            'year' => 2026,
            'employee_ids' => [$this->employee->id, $employee2->id]
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 200,
            'message' => '2 payroll record(s) generated successfully'
        ]);

        $this->assertDatabaseHas('payrolls', [
            'employee_id' => $this->employee->id,
            'month' => 6,
            'year' => 2026
        ]);

        $this->assertDatabaseHas('payrolls', [
            'employee_id' => $employee2->id,
            'month' => 6,
            'year' => 2026
        ]);
    }

    public function test_uses_employee_payrolls_table_details_if_available()
    {
        // 25 present days in June 2026
        for ($i = 1; $i <= 25; $i++) {
            AttendanceProcessed::create([
                'employee_id' => $this->employee->id,
                'date' => sprintf('2026-06-%02d', $i),
                'attendance_status' => 'present'
            ]);
        }

        // 5 absent days
        for ($i = 26; $i <= 30; $i++) {
            AttendanceProcessed::create([
                'employee_id' => $this->employee->id,
                'date' => sprintf('2026-06-%02d', $i),
                'attendance_status' => 'absent'
            ]);
        }

        // Supersede the salary record from setUp with a revised one
        $this->employee->activePayroll->update(['is_active' => false]);

        \App\Models\EmployeePayroll::create([
            'employee_id' => $this->employee->id,
            'salary_type' => 'monthly',
            'basic_salary' => 30000.00,
            'pf_applicable' => true,
            'pf_amount' => 2000.00,
            'mess_deduction_applicable' => true,
            'mess_deduction_amount' => 1000.00,
            'other_deduction_appliacble' => true,
            'other_deduction' => 500.00,
            'is_active' => true
        ]);

        // 1. Verify index method lists it correctly
        // Earnings: Basic (30000) = 30000 gross
        // Per day salary: 30000 / 30 = 1000
        // Days worked: 25 present. Holidays = 0. Rest days = 0. Paid leaves = 0.
        // Effective absent: 5 days absent.
        // Leave deduction: 5 * 1000 = 5000
        // PF (2000) + Mess (1000) + Other Deduction (500) = 3500 fixed deductions
        // Net salary = 30000 - 5000 - 3500 = 21500
        $response = $this->getJson('/api/v1/admin/payroll?month=6&year=2026');
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'employee_code' => 'EMP5001',
            'basic_salary' => 30000,
            'gross_salary' => 30000,
            'pf_deduction' => 2000,
            'mess_deduction' => 1000,
            'other_deduction' => 500,
            'payable_days' => 25,
            'leave_deduction' => 5000,
            'net_salary' => 21500
        ]);

        // 2. Generate payroll and assert database record has correct values
        $genResponse = $this->postJson('/api/v1/admin/payroll/generate', [
            'month' => 6,
            'year' => 2026,
            'employee_id' => $this->employee->id
        ]);
        $genResponse->assertStatus(200);

        $this->assertDatabaseHas('payrolls', [
            'employee_id' => $this->employee->id,
            'month' => 6,
            'year' => 2026,
            'basic_salary' => 30000,
            'pf_deduction' => 2000,
            'mess_deduction' => 1000,
            'other_deduction' => 500,
            'leave_deduction' => 5000,
            'net_salary' => 21500
        ]);

        // 3. Verify show method returns correct detailed response
        $showResponse = $this->getJson("/api/v1/admin/payroll/{$this->employee->id}/detail?month=6&year=2026");
        $showResponse->assertStatus(200);
        $showResponse->assertJsonPath('data.earnings.basic_salary', 30000);
        $showResponse->assertJsonPath('data.deductions.pf_deduction', 2000);
        $showResponse->assertJsonPath('data.deductions.mess_deduction', 1000);
        $showResponse->assertJsonPath('data.deductions.other_deduction', 500);
        $showResponse->assertJsonPath('data.attendance.payable_days', 25);
        $showResponse->assertJsonPath('data.net_salary', 21500);
    }

    public function test_cannot_create_duplicate_employee_payroll_configuration()
    {
        $employee = Employee::create([
            'employee_code' => 'TESTEMP99',
            'name' => 'Test Employee',
            'joining_date' => '2026-01-01',
            'is_active' => 1,
            'basic_salary' => 20000,
            'designation_id' => $this->role->id,
        ]);

        // Attempt to create first employee payroll config
        $responseStore1 = $this->postJson('/api/v1/admin/employee-payrolls', [
            'employee_id' => $employee->id,
            'salary_type' => 'monthly',
            'basic_salary' => 20000,
        ]);
        $responseStore1->assertStatus(200);

        // Attempt to create second employee payroll config (duplicate)
        $responseStore2 = $this->postJson('/api/v1/admin/employee-payrolls', [
            'employee_id' => $employee->id,
            'salary_type' => 'monthly',
            'basic_salary' => 25000,
        ]);
        $responseStore2->assertStatus(422);
        $responseStore2->assertJsonValidationErrors(['employee_id']);
    }

    public function test_payroll_respects_employee_joining_date()
    {
        // Create an employee who joins on 2026-06-10
        $employeeJoinedLate = Employee::create([
            'employee_code' => 'EMP9010',
            'name' => 'Late Payroll Joiner',
            'joining_date' => '2026-06-10',
            'is_active' => 1,
            'basic_salary' => 20000,
            'designation_id' => $this->role->id,
        ]);

        // 1. List Payroll for May 2026: Late Joiner should NOT appear
        $responseMay = $this->getJson('/api/v1/admin/payroll?month=5&year=2026');
        $responseMay->assertStatus(200);
        $this->assertNull(collect($responseMay->json('data'))->firstWhere('id', $employeeJoinedLate->id));

        // 2. List Payroll for June 2026: Late Joiner SHOULD appear
        $responseJune = $this->getJson('/api/v1/admin/payroll?month=6&year=2026');
        $responseJune->assertStatus(200);
        $this->assertNotNull(collect($responseJune->json('data'))->firstWhere('id', $employeeJoinedLate->id));

        // 3. Generate Payroll for May 2026 with employee_id: should return 404 (No employees found)
        $responseGenMay = $this->postJson('/api/v1/admin/payroll/generate', [
            'month' => 5,
            'year' => 2026,
            'employee_id' => $employeeJoinedLate->id,
        ]);
        $responseGenMay->assertStatus(404);

        // 4. Show Payroll Details for May 2026: should return 404
        $responseShowMay = $this->getJson("/api/v1/admin/payroll/{$employeeJoinedLate->id}/detail?month=5&year=2026");
        $responseShowMay->assertStatus(404);

        // 5. Show Payroll Details for June 2026: should return 200
        \App\Models\EmployeePayroll::create([
            'employee_id' => $employeeJoinedLate->id,
            'salary_type' => 'monthly',
            'basic_salary' => 20000,
            'is_active' => true,
        ]);
        $responseShowJune = $this->getJson("/api/v1/admin/payroll/{$employeeJoinedLate->id}/detail?month=6&year=2026");
        $responseShowJune->assertStatus(200);
    }
}
