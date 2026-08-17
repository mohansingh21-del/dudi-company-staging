<?php

namespace Tests\Feature;

use App\Models\AttendanceProcessed;
use App\Models\Employee;
use App\Models\Relay;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Attendance Register (Form D) endpoint.
 */
class AttendanceRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected $employee;
    protected $site;
    protected $shift;
    protected $relay;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'System-Administrator',
            'slug' => 'super-admin',
            'is_active' => 1,
        ]);

        $admin = User::create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
        ]);
        $admin->roles()->attach($role);

        Sanctum::actingAs($admin);

        $workerRole = Role::create([
            'name' => 'Worker',
            'slug' => 'worker',
            'is_active' => 1,
        ]);

        DB::table('designations')->insert([
            'id' => $workerRole->id,
            'designation_name' => 'Worker',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->site = Site::create([
            'site_name' => 'Coal Mine Alpha',
            'is_active' => 1,
        ]);

        $shiftId = DB::table('shifts')->insertGetId([
            'shift_name' => 'Day Shift A',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->shift = Shift::find($shiftId);

        $this->relay = Relay::create([
            'name' => 'Relay A',
            'is_rotating' => true,
            'is_active' => true,
        ]);

        $this->employee = Employee::create([
            'employee_code' => 'EMP7001',
            'name' => 'Amit',
            'surname' => 'Sharma',
            'joining_date' => '2026-01-01',
            'is_active' => 1,
            'designation_id' => $workerRole->id,
            'site_id' => $this->site->id,
            'relay_id' => $this->relay->id,
        ]);
    }

    private function mark(array $attributes)
    {
        return AttendanceProcessed::create(array_merge([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shift->id,
            'working_hours' => 9.0,
        ], $attributes));
    }

    public function test_register_returns_a_day_cell_for_every_day_of_the_month()
    {
        $this->mark([
            'date' => '2026-06-05',
            'check_in' => '2026-06-05 09:00:00',
            'check_out' => '2026-06-05 18:00:00',
            'attendance_status' => 'present',
            'place_of_work' => 'opencast',
        ]);

        $response = $this->getJson('/api/v1/admin/attendance/register?month=6&year=2026');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'month',
                    'month_num',
                    'year',
                    'days_in_month',
                    'rows' => [
                        '*' => [
                            'serial_no',
                            'employee_id',
                            'employee_code',
                            'name',
                            'relay',
                            'place_of_work',
                            'place_of_work_label',
                            'days',
                            'total_days',
                            'remarks',
                        ],
                    ],
                    'pagination',
                ],
            ]);

        $row = $response->json('data.rows.0');

        $this->assertSame(30, $response->json('data.days_in_month'));
        $this->assertCount(30, $row['days']);
        $this->assertSame(1, $row['serial_no']);
        $this->assertSame('Amit Sharma', $row['name']);
        $this->assertSame('Relay A', $row['relay']);

        // The marked day carries IN/OUT; every other day is an empty cell.
        $this->assertSame('09:00', $row['days']['5']['in']);
        $this->assertSame('18:00', $row['days']['5']['out']);
        $this->assertSame('present', $row['days']['5']['status']);
        $this->assertNull($row['days']['6']['in']);
        $this->assertNull($row['days']['6']['status']);
    }

    public function test_total_days_counts_half_days_as_half()
    {
        $this->mark([
            'date' => '2026-06-01',
            'check_in' => '2026-06-01 09:00:00',
            'check_out' => '2026-06-01 18:00:00',
            'attendance_status' => 'present',
        ]);

        $this->mark([
            'date' => '2026-06-02',
            'check_in' => '2026-06-02 09:00:00',
            'check_out' => '2026-06-02 13:00:00',
            'attendance_status' => 'half_day',
        ]);

        // Neither of these adds to the worked-days total.
        $this->mark(['date' => '2026-06-03', 'attendance_status' => 'absent']);
        $this->mark(['date' => '2026-06-04', 'attendance_status' => 'rest_day']);

        $response = $this->getJson('/api/v1/admin/attendance/register?month=6&year=2026');

        $response->assertStatus(200);
        $this->assertSame(1.5, $response->json('data.rows.0.total_days'));
    }

    public function test_place_of_work_reports_every_location_worked_in_the_month()
    {
        $this->mark([
            'date' => '2026-06-01',
            'attendance_status' => 'present',
            'place_of_work' => 'opencast',
        ]);

        $response = $this->getJson('/api/v1/admin/attendance/register?month=6&year=2026');
        $this->assertSame('opencast', $response->json('data.rows.0.place_of_work'));
        $this->assertSame('Opencast', $response->json('data.rows.0.place_of_work_label'));

        // Moved underground mid-month: column 4 is one cell, so it lists both
        // rather than silently picking one.
        $this->mark([
            'date' => '2026-06-02',
            'attendance_status' => 'present',
            'place_of_work' => 'underground',
        ]);

        $response = $this->getJson('/api/v1/admin/attendance/register?month=6&year=2026');
        $this->assertNull($response->json('data.rows.0.place_of_work'));
        $this->assertSame('Opencast, Underground', $response->json('data.rows.0.place_of_work_label'));
    }

    public function test_register_excludes_employees_who_had_left_before_the_month()
    {
        Employee::create([
            'employee_code' => 'EMP7002',
            'name' => 'Ravi',
            'surname' => 'Verma',
            'joining_date' => '2026-01-01',
            'date_of_exit' => '2026-03-31',
            'reason_for_exit' => 'Resigned',
            'is_active' => 0,
            'site_id' => $this->site->id,
        ]);

        $response = $this->getJson('/api/v1/admin/attendance/register?month=6&year=2026');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.rows'));
        $this->assertSame('Amit Sharma', $response->json('data.rows.0.name'));

        // employee_status=all keeps them on the register.
        $all = $this->getJson('/api/v1/admin/attendance/register?month=6&year=2026&employee_status=all');
        $this->assertCount(2, $all->json('data.rows'));
    }

    public function test_register_rejects_an_invalid_month()
    {
        $this->getJson('/api/v1/admin/attendance/register?month=13&year=2026')
            ->assertStatus(422);
    }

    public function test_serial_numbers_continue_across_pages()
    {
        foreach (range(2, 4) as $n) {
            Employee::create([
                'employee_code' => 'EMP700' . $n,
                'name' => 'Worker',
                'surname' => (string) $n,
                'joining_date' => '2026-01-01',
                'is_active' => 1,
                'site_id' => $this->site->id,
            ]);
        }

        $page2 = $this->getJson('/api/v1/admin/attendance/register?month=6&year=2026&limit=2&page=2');

        $page2->assertStatus(200);
        $this->assertSame(3, $page2->json('data.rows.0.serial_no'));
        $this->assertSame(4, $page2->json('data.rows.1.serial_no'));
    }
}
