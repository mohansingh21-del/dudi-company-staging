<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\Role;
use App\Models\AttendanceProcessed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Imports\AttendanceImport;
use Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BulkAttendanceImportTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'Worker',
            'slug' => 'worker',
            'is_active' => 1
        ]);

        DB::table('designations')->insert([
            'id' => $role->id,
            'designation_name' => 'Worker',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->employee = Employee::create([
            'employee_code' => 'EMP101',
            'name' => 'John Doe',
            'joining_date' => '2026-01-01',
            'is_active' => 1,
            'designation_id' => $role->id,
        ]);
    }

    /** @test */
    public function it_imports_bulk_attendance_with_seconds_in_time_format()
    {
        $rows = collect([
            [
                'employee_code' => 'EMP101',
                'date' => Carbon::now()->subDay()->format('d/m/Y'),
                'check_in' => '09:00:00',
                'check_out' => '17:00:00',
                'status' => 'present',
                'remarks' => 'On time',
            ]
        ]);

        $import = new AttendanceImport();
        $import->collection($rows);

        $this->assertDatabaseHas('attendance_processed', [
            'employee_id' => $this->employee->id,
            'attendance_status' => 'present',
            'working_hours' => 8,
        ]);
    }

    /** @test */
    public function it_imports_bulk_attendance_with_ymd_date_format()
    {
        $rows = collect([
            [
                'employee_code' => 'EMP101',
                'date' => Carbon::now()->subDay()->format('Y-m-d'),
                'check_in' => '09:00',
                'check_out' => '17:00',
                'status' => 'Present',
                'remarks' => 'Regular',
            ]
        ]);

        $import = new AttendanceImport();
        $import->collection($rows);

        $this->assertDatabaseHas('attendance_processed', [
            'employee_id' => $this->employee->id,
            'attendance_status' => 'present',
        ]);
    }

    /** @test */
    public function it_imports_bulk_attendance_with_excel_time_seconds_or_datetime_string()
    {
        $rows = collect([
            [
                'employee_code' => 'EMP101',
                'date' => Carbon::now()->subDay()->format('d/m/Y') . ' 00:00:00',
                'check_in' => '09:30:00',
                'check_out' => '18:00:00',
                'status' => 'present',
                'remarks' => 'With trailing seconds',
            ]
        ]);

        $import = new AttendanceImport();
        $import->collection($rows);

        $this->assertDatabaseHas('attendance_processed', [
            'employee_id' => $this->employee->id,
            'attendance_status' => 'present',
            'working_hours' => 8.5,
        ]);
    }
}

