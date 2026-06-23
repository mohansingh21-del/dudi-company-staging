<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\Employee;
use App\Models\ShiftPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftPlanListPeriodFilterTest extends TestCase
{
    use RefreshDatabase;

    private $adminUser;
    private $supervisorEmployee;
    private $siteInchargeEmployee;
    private $shift;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1
        ]);

        $this->adminUser = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $this->adminUser->roles()->attach($adminRole);

        // Create Supervisor Role, User, and Employee
        $supervisorRole = Role::create([
            'name' => 'Supervisor',
            'slug' => 'supervisor',
            'is_active' => 1
        ]);
        $supervisorUser = User::create([
            'email' => 'supervisor@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $supRoleUser = RoleUser::create([
            'user_id' => $supervisorUser->id,
            'role_id' => $supervisorRole->id
        ]);
        $this->supervisorEmployee = Employee::create([
            'employee_code' => 'EMP-SUP-001',
            'name' => 'John Supervisor',
            'father_name' => 'Father',
            'dob' => '1990-01-01',
            'gender' => 'male',
            'mobile' => '9999999999',
            'address' => 'Colony 1',
            'joining_date' => '2026-01-01',
            'employee_type' => 'permanent',
            'designation_id' => $supervisorRole->id,
            'salary_type' => 'monthly',
            'basic_salary' => 30000,
            'is_active' => 1,
            'role_user_id' => $supRoleUser->id
        ]);

        // Create Site Incharge Role, User, and Employee
        $inchargeRole = Role::create([
            'name' => 'Site Incharge',
            'slug' => 'site-incharge',
            'is_active' => 1
        ]);
        $inchargeUser = User::create([
            'email' => 'incharge@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1
        ]);
        $incRoleUser = RoleUser::create([
            'user_id' => $inchargeUser->id,
            'role_id' => $inchargeRole->id
        ]);
        $this->siteInchargeEmployee = Employee::create([
            'employee_code' => 'EMP-INC-001',
            'name' => 'Alice Incharge',
            'father_name' => 'Father',
            'dob' => '1990-01-01',
            'gender' => 'female',
            'mobile' => '9999999998',
            'address' => 'Colony 2',
            'joining_date' => '2026-01-01',
            'employee_type' => 'permanent',
            'designation_id' => $inchargeRole->id,
            'salary_type' => 'monthly',
            'basic_salary' => 50000,
            'is_active' => 1,
            'role_user_id' => $incRoleUser->id
        ]);

        // Create Shift & Site
        $this->shift = Shift::create([
            'shift_name' => 'A',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'minimum_working_hours' => 8.00,
            'is_night_shift' => 0,
            'is_active' => 1
        ]);

        $this->site = Site::create([
            'site_name' => 'Block-04 West',
            'address' => 'Site Address',
            'is_active' => 1
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($this->adminUser);
    }

    public function test_can_filter_list_by_period()
    {
        $today = \Carbon\Carbon::now();

        // 1. Shift plan for today
        ShiftPlan::create([
            'planning_date' => $today->format('Y-m-d'),
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-TODAY'
        ]);

        // 2. Shift plan for next month (same quarter, if we are in month 1 or 2 of quarter; to be safe let's add 1 month)
        // Let's create one explicitly in another month
        $nextMonthDate = $today->copy()->addMonth();
        ShiftPlan::create([
            'planning_date' => $nextMonthDate->format('Y-m-d'),
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-NEXT-MONTH'
        ]);

        // 3. Shift plan for next year
        $nextYearDate = $today->copy()->addYear();
        ShiftPlan::create([
            'planning_date' => $nextYearDate->format('Y-m-d'),
            'shift_id' => $this->shift->id,
            'site_id' => $this->site->id,
            'target_bcm' => 45000,
            'supervisor_id' => $this->supervisorEmployee->roleUser->user_id,
            'site_incharge_id' => $this->siteInchargeEmployee->roleUser->user_id,
            'status' => 'active',
            'created_by' => $this->adminUser->id,
            'reference_no' => 'SP-NEXT-YEAR'
        ]);

        // Test Monthly filter: Should contain SP-TODAY but not SP-NEXT-MONTH or SP-NEXT-YEAR (unless month boundaries overlap)
        $responseMonthly = $this->getJson("/api/v1/admin/shift-plans?period=monthly&date={$today->format('Y-m-d')}");
        $responseMonthly->assertStatus(200);
        $dataMonthly = $responseMonthly->json('data');
        // Let's assert that the reference number is SP-TODAY
        $refCodesMonthly = collect($dataMonthly)->pluck('reference_no')->toArray();
        $this->assertContains('SP-TODAY', $refCodesMonthly);
        $this->assertNotContains('SP-NEXT-MONTH', $refCodesMonthly);
        $this->assertNotContains('SP-NEXT-YEAR', $refCodesMonthly);

        // Test Yearly filter: Should contain SP-TODAY and SP-NEXT-MONTH (if in the same year) but not SP-NEXT-YEAR
        $responseYearly = $this->getJson("/api/v1/admin/shift-plans?period=yearly&date={$today->format('Y-m-d')}");
        $responseYearly->assertStatus(200);
        $dataYearly = $responseYearly->json('data');
        $refCodesYearly = collect($dataYearly)->pluck('reference_no')->toArray();
        $this->assertContains('SP-TODAY', $refCodesYearly);
        $this->assertNotContains('SP-NEXT-YEAR', $refCodesYearly);
    }
}
