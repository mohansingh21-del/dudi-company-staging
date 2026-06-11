<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\Payroll;
use App\Models\SalaryStructure;
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

        // Create salary structure for the designation (Worker)
        SalaryStructure::create([
            'designation_id' => $this->role->id,
            'shift_allowance' => 1500.00,
            'incentives' => 500.00,
            'is_active' => 1
        ]);

        // Create test employee
        $this->employee = Employee::create([
            'employee_code' => 'EMP5001',
            'name' => 'Amit Sharma',
            'joining_date' => '2026-01-01',
            'is_active' => 1,
            'basic_salary' => 25000.00,
            'designation_id' => $this->role->id,
            'pf_applicable' => 1,
            'pf_amount' => 1200.00,
            'mess_deduction_applicable' => 1,
            'mess_deduction_amount' => 500.00
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

        // 1 penalty in June 2026
        Penalty::create([
            'employee_id' => $this->employee->id,
            'penalty_date' => '2026-06-02',
            'month' => 6,
            'year' => 2026,
            'reason' => 'Late',
            'amount' => 2000.00
        ]);

        // Earnings: Basic (25000) + Allowance (1500) + Incentives (500) = 27000 gross
        // Per day salary: 27000 / 30 = 900
        // Days worked: 1 present. Holidays = 0. Rest days = 0. Paid leaves = 0.
        // Effective absent: 30 - 1 = 29 days absent (unmarked count as absent)
        // Leave deduction: 29 absent * 900 = 26100.
        // PF (1200) + Mess (500) + Penalty (2000) = 3700 other deductions
        // Total deductions = 26100 + 3700 = 29800
        // Net salary = max(0, 27000 - 29800) = 0.00

        $response = $this->getJson('/api/v1/admin/payroll?month=6&year=2026');
        $response->assertStatus(200);

        $response->assertJsonFragment([
            'employee_code' => 'EMP5001',
            'basic_salary' => 25000,
            'shift_allowance' => 1500,
            'incentives' => 500,
            'gross_salary' => 27000,
            'present_days' => 1,
            'absent_days' => 29,
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
}
