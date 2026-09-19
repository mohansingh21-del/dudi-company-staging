<?php

namespace Tests\Feature;

use App\Models\AttendanceProcessed;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HolidayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Holidays are counted as distinct DATES, and a date is paid once.
 *
 * Every case here was a real over-count before: duplicate rows paid a day
 * each, a general and a site holiday on one date paid two, and a holiday
 * landing on a weekly off paid on top of the rest day.
 */
class HolidayCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected $site;
    protected $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Worker', 'slug' => 'worker', 'is_active' => 1]);

        DB::table('designations')->insert([
            'id' => $role->id,
            'designation_name' => 'Worker',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->site = Site::create(['site_name' => 'Alpha', 'is_active' => true]);

        $this->employee = Employee::create([
            'name' => 'Amit',
            'employee_code' => 'EMP9001',
            'site_id' => $this->site->id,
            'designation_id' => $role->id,
            'joining_date' => '2026-01-01',
            'is_active' => 1,
        ]);
    }

    /** Duplicates are written straight to the table: the API can no longer create them. */
    private function seedHoliday(string $name, string $date, $siteId, int $active = 1): void
    {
        DB::table('holidays')->insert([
            'holiday_name' => $name,
            'holiday_date' => $date,
            'site_id' => $siteId,
            'holiday_type' => null,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function holidayDays(): int
    {
        return HolidayService::employeeHolidayDays($this->employee->id, 8, 2026);
    }

    public function test_three_rows_on_one_date_count_as_one_day()
    {
        // Legacy duplicates, from before the unique index existed.
        $this->seedHoliday('Independence Day', '2026-08-15', $this->site->id, 0);
        $this->seedHoliday('independence day', '2026-08-15', $this->site->id, 0);
        $this->seedHoliday('Independence Day', '2026-08-15', $this->site->id, 1);

        $this->assertSame(1, $this->holidayDays());
    }

    public function test_general_and_site_holiday_on_one_date_count_as_one_day()
    {
        $this->seedHoliday('Independence Day', '2026-08-15', null);
        $this->seedHoliday('Independence Day', '2026-08-15', $this->site->id);

        $this->assertSame(1, $this->holidayDays());
    }

    public function test_inactive_holidays_are_not_counted()
    {
        $this->seedHoliday('Retired entry', '2026-08-15', $this->site->id, 0);

        $this->assertSame(0, $this->holidayDays());
    }

    public function test_another_sites_holiday_is_not_counted()
    {
        $other = Site::create(['site_name' => 'Beta', 'is_active' => true]);
        $this->seedHoliday('Beta only', '2026-08-15', $other->id);

        $this->assertSame(0, $this->holidayDays());
    }

    public function test_general_holiday_reaches_an_employee_with_no_site()
    {
        $this->employee->update(['site_id' => null]);
        $this->seedHoliday('Republic Day', '2026-08-15', null);

        $this->assertSame(1, $this->holidayDays());
    }

    public function test_holiday_on_a_weekly_off_is_not_paid_twice()
    {
        $this->seedHoliday('Independence Day', '2026-08-15', $this->site->id);
        $this->seedHoliday('Local Festival', '2026-08-20', $this->site->id);

        AttendanceProcessed::create([
            'employee_id' => $this->employee->id,
            'date' => '2026-08-15',
            'attendance_status' => 'rest_day',
        ]);

        // 15-Aug is already paid as the weekly off; only 20-Aug is left.
        $this->assertSame(1, $this->holidayDays());
    }

    public function test_holiday_on_a_worked_day_is_not_paid_twice()
    {
        $this->seedHoliday('Independence Day', '2026-08-15', $this->site->id);

        AttendanceProcessed::create([
            'employee_id' => $this->employee->id,
            'date' => '2026-08-15',
            'attendance_status' => 'present',
        ]);

        $this->assertSame(0, $this->holidayDays());
    }

    public function test_holiday_on_an_absent_day_is_still_paid()
    {
        $this->seedHoliday('Independence Day', '2026-08-15', $this->site->id);

        AttendanceProcessed::create([
            'employee_id' => $this->employee->id,
            'date' => '2026-08-15',
            'attendance_status' => 'absent',
        ]);

        // An absent day pays nothing, so the holiday is the only thing paying.
        $this->assertSame(1, $this->holidayDays());
    }

    public function test_holidays_outside_the_month_are_ignored()
    {
        $this->seedHoliday('July holiday', '2026-07-31', $this->site->id);
        $this->seedHoliday('September holiday', '2026-09-01', $this->site->id);

        $this->assertSame(0, $this->holidayDays());
    }

    public function test_batch_and_single_lookups_agree()
    {
        $this->seedHoliday('Independence Day', '2026-08-15', null);
        $this->seedHoliday('Independence Day', '2026-08-15', $this->site->id);
        $this->seedHoliday('Local Festival', '2026-08-20', $this->site->id);

        $batch = HolidayService::monthlyHolidayDays([$this->employee->id], 8, 2026);

        $this->assertSame(2, $batch[$this->employee->id]);
        $this->assertSame(2, $this->holidayDays());
    }

    public function test_empty_employee_list_returns_no_rows()
    {
        $this->assertSame([], HolidayService::monthlyHolidayDays([], 8, 2026));
    }
}
