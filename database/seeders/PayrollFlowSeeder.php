<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Employee;
use App\Models\Role;
use App\Models\EmployeePayroll;
use App\Models\AttendanceProcessed;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Penalty;
use App\Models\Holiday;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PayrollFlowSeeder extends Seeder
{
    public function run()
    {
        // 1. Create or get designations
        $workerRole = Role::where('slug', 'worker')->first();
        if (!$workerRole) {
            $workerRole = Role::create(['name' => 'Worker', 'slug' => 'worker']);
        }
        
        // Satisfy designations DB constraint if not already matching role ID
        DB::table('designations')->updateOrInsert(
            ['id' => $workerRole->id],
            ['designation_name' => 'Worker', 'created_at' => now(), 'updated_at' => now()]
        );

        // 2. Create Departments
        $deptMiningId = DB::table('departments')->insertGetId([
            'name' => 'Mining Operations',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        $deptSecurityId = DB::table('departments')->insertGetId([
            'name' => 'Security',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 3. Create Sites
        $siteCoalId = DB::table('sites')->insertGetId([
            'site_name' => 'Dudi Coal Mine Area A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $siteOfficeId = DB::table('sites')->insertGetId([
            'site_name' => 'Main Office HQ',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 4. Create Shifts
        $shiftGeneralId = DB::table('shifts')->insertGetId([
            'shift_name' => 'General Day Shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 5. Create Holidays for June 2026
        // Holiday on June 15, 2026 for Coal Mine
        Holiday::create([
            'holiday_name' => 'Mid Year Festival',
            'holiday_date' => '2026-06-15',
            'site_id' => $siteCoalId,
            'is_active' => true
        ]);
        
        // Site specific holiday for Coal Mine on June 25, 2026
        Holiday::create([
            'holiday_name' => 'Coal Mine Special Day',
            'holiday_date' => '2026-06-25',
            'site_id' => $siteCoalId,
            'is_active' => true
        ]);

        // 6. Create Leave Types
        $paidLeaveType = LeaveType::create([
            'name' => 'Paid Annual Leave',
            'leave_category' => 'paid',
            'allowed_days' => 15,
            'is_active' => true
        ]);

        $unpaidLeaveType = LeaveType::create([
            'name' => 'Unpaid Sick Leave',
            'leave_category' => 'unpaid',
            'allowed_days' => 30,
            'is_active' => true
        ]);

        // June 2026 details
        $year = 2026;
        $month = 6;
        $daysInMonth = 30;

        // ── CASE 1: Standard Employee (Monthly Salary with PF and Mess Deductions) ──
        $emp1 = Employee::create([
            'employee_code' => 'EMP202601',
            'name' => 'Rajesh Kumar',
            'joining_date' => '2025-01-10',
            'is_active' => true,
            'basic_salary' => 28000.00,
            'designation_id' => $workerRole->id,
            'department_id' => $deptMiningId,
            'site_id' => $siteCoalId,
            'pf_applicable' => true,
            'pf_amount' => 1500.00,
            'mess_deduction_applicable' => true,
            'mess_deduction_amount' => 800.00
        ]);

        EmployeePayroll::create([
            'employee_id' => $emp1->id,
            'salary_type' => 'monthly',
            'basic_salary' => 28000.00,
            'pf_applicable' => true,
            'pf_number' => 'PF-RAJ-123',
            'bank_name' => 'State Bank of India',
            'bank_account_number' => '1234567890',
            'ifsc_code' => 'SBIN0001234',
            'mess_deduction_applicable' => true,
            'other_deduction_appliacble' => false,
            'other_deduction' => 0.00,
            'is_active' => true
        ]);

        // Assign Shift
        DB::table('employee_shift_assignments')->insert([
            'employee_id' => $emp1->id,
            'shift_id' => $shiftGeneralId,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Set up attendance:
        // - 20 present days (June 1 to 20)
        // - 4 rest days (June 21, 22, 23, 24)
        // - rest are unmarked (will count as absent)
        for ($day = 1; $day <= 20; $day++) {
            AttendanceProcessed::create([
                'employee_id' => $emp1->id,
                'date' => sprintf('2026-06-%02d', $day),
                'attendance_status' => 'present'
            ]);
        }
        for ($day = 21; $day <= 24; $day++) {
            AttendanceProcessed::create([
                'employee_id' => $emp1->id,
                'date' => sprintf('2026-06-%02d', $day),
                'attendance_status' => 'rest_day'
            ]);
        }


        // ── CASE 2: Daily Wage Employee ──
        $emp2 = Employee::create([
            'employee_code' => 'EMP202602',
            'name' => 'Suresh Patil',
            'joining_date' => '2025-06-15',
            'is_active' => true,
            'basic_salary' => 0.00,
            'designation_id' => $workerRole->id,
            'department_id' => $deptMiningId,
            'site_id' => $siteCoalId,
            'pf_applicable' => false,
            'mess_deduction_applicable' => false
        ]);

        EmployeePayroll::create([
            'employee_id' => $emp2->id,
            'salary_type' => 'daily_wage',
            'daily_wage' => 850.00,
            'pf_applicable' => false,
            'bank_name' => 'Bank of Baroda',
            'bank_account_number' => '0987654321',
            'ifsc_code' => 'BARB0INDORE',
            'mess_deduction_applicable' => false,
            'is_active' => true
        ]);

        DB::table('employee_shift_assignments')->insert([
            'employee_id' => $emp2->id,
            'shift_id' => $shiftGeneralId,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Attendance: 15 present days in June
        for ($day = 1; $day <= 15; $day++) {
            AttendanceProcessed::create([
                'employee_id' => $emp2->id,
                'date' => sprintf('2026-06-%02d', $day),
                'attendance_status' => 'present'
            ]);
        }


        // ── CASE 3: Employee with approved leaves (Paid and Unpaid) ──
        $emp3 = Employee::create([
            'employee_code' => 'EMP202603',
            'name' => 'Vikram Singh',
            'joining_date' => '2025-03-01',
            'is_active' => true,
            'basic_salary' => 30000.00,
            'designation_id' => $workerRole->id,
            'department_id' => $deptSecurityId,
            'site_id' => $siteOfficeId,
            'pf_applicable' => true,
            'pf_amount' => 1200.00,
            'mess_deduction_applicable' => false
        ]);

        EmployeePayroll::create([
            'employee_id' => $emp3->id,
            'salary_type' => 'monthly',
            'basic_salary' => 30000.00,
            'pf_applicable' => true,
            'pf_number' => 'PF-VIK-789',
            'bank_name' => 'HDFC Bank',
            'bank_account_number' => '5566778899',
            'ifsc_code' => 'HDFC0000123',
            'mess_deduction_applicable' => false,
            'is_active' => true
        ]);

        DB::table('employee_shift_assignments')->insert([
            'employee_id' => $emp3->id,
            'shift_id' => $shiftGeneralId,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Attendance: 15 present days (1 to 15), 5 rest days (16 to 20)
        for ($day = 1; $day <= 15; $day++) {
            AttendanceProcessed::create([
                'employee_id' => $emp3->id,
                'date' => sprintf('2026-06-%02d', $day),
                'attendance_status' => 'present'
            ]);
        }
        for ($day = 16; $day <= 20; $day++) {
            AttendanceProcessed::create([
                'employee_id' => $emp3->id,
                'date' => sprintf('2026-06-%02d', $day),
                'attendance_status' => 'rest_day'
            ]);
        }

        // Leave: 3 days Approved Paid Leave (June 21 to 23)
        Leave::create([
            'employee_id' => $emp3->id,
            'leave_type_id' => $paidLeaveType->id,
            'from_date' => '2026-06-21',
            'to_date' => '2026-06-23',
            'reason' => 'Family visit',
            'status' => 'approved'
        ]);

        // Leave: 4 days Approved Unpaid Leave (June 24 to 27)
        Leave::create([
            'employee_id' => $emp3->id,
            'leave_type_id' => $unpaidLeaveType->id,
            'from_date' => '2026-06-24',
            'to_date' => '2026-06-27',
            'reason' => 'Medical recovery',
            'status' => 'approved'
        ]);


        // ── CASE 4: Employee with Penalties and Other Deductions ──
        $emp4 = Employee::create([
            'employee_code' => 'EMP202604',
            'name' => 'Amit Sharma',
            'joining_date' => '2025-08-01',
            'is_active' => true,
            'basic_salary' => 24000.00,
            'designation_id' => $workerRole->id,
            'department_id' => $deptMiningId,
            'site_id' => $siteCoalId,
            'pf_applicable' => false,
            'mess_deduction_applicable' => false
        ]);

        EmployeePayroll::create([
            'employee_id' => $emp4->id,
            'salary_type' => 'monthly',
            'basic_salary' => 24000.00,
            'pf_applicable' => false,
            'mess_deduction_applicable' => false,
            'other_deduction_appliacble' => true,
            'other_deduction' => 1200.00,
            'is_active' => true
        ]);

        DB::table('employee_shift_assignments')->insert([
            'employee_id' => $emp4->id,
            'shift_id' => $shiftGeneralId,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Attendance: 22 present days, 4 rest days, 2 holidays, 2 absent days
        for ($day = 1; $day <= 22; $day++) {
            AttendanceProcessed::create([
                'employee_id' => $emp4->id,
                'date' => sprintf('2026-06-%02d', $day),
                'attendance_status' => 'present'
            ]);
        }
        for ($day = 23; $day <= 26; $day++) {
            AttendanceProcessed::create([
                'employee_id' => $emp4->id,
                'date' => sprintf('2026-06-%02d', $day),
                'attendance_status' => 'rest_day'
            ]);
        }

        // Penalties: 1 Penalty of 1500.00 on June 10
        Penalty::create([
            'employee_id' => $emp4->id,
            'penalty_date' => '2026-06-10',
            'month' => 6,
            'year' => 2026,
            'reason' => 'Damaged equipment',
            'amount' => 1500.00
        ]);
    }
}
