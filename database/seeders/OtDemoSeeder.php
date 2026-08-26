<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\AttendanceProcessed;
use Carbon\Carbon;

/**
 * Three months of demo data built to make overtime legible.
 *
 * The point of the dataset is the interaction between weekly shift rotation and
 * overtime: the three rotating shifts are deliberately NOT the same length, so a
 * single month spans several different OT baselines and the per-day nature of
 * the calculation is visible in the numbers rather than only in the code.
 *
 * attendance_processeds.shift_id is left NULL on most rows on purpose — that is
 * what live data looks like, and it forces every reader through
 * ShiftRosterResolver instead of letting them read the shift off the row.
 */
class OtDemoSeeder extends Seeder
{
    /** Monday. Rotation weeks run Monday-Sunday, same as RotateShiftsCommand. */
    private const START = '2026-06-01';

    /** Today — attendance stops here rather than running into the future. */
    private const END = '2026-08-25';

    /**
     * Shift lengths differ on purpose. Rotating between an 8h and a 6h shift is
     * what makes "monthly OT" visibly a sum of differently-priced days.
     */
    private const SHIFTS = [
        1 => ['shift_name' => 'Morning (A)',   'start_time' => '06:00:00', 'end_time' => '14:00:00', 'minimum_working_hours' => 8, 'is_night_shift' => 0], // 8h
        2 => ['shift_name' => 'Afternoon (B)', 'start_time' => '14:00:00', 'end_time' => '20:00:00', 'minimum_working_hours' => 6, 'is_night_shift' => 0], // 6h
        3 => ['shift_name' => 'Night (C)',     'start_time' => '22:00:00', 'end_time' => '06:00:00', 'minimum_working_hours' => 8, 'is_night_shift' => 1], // 8h, crosses midnight
    ];

    /**
     * RotateShiftsCommand orders active shifts by start_time DESC and steps one
     * place each Sunday, so the cycle is Night -> Afternoon -> Morning -> Night.
     * Mirrored here so the seeded history matches what the command would produce.
     */
    private const ROTATION_NEXT = [3 => 2, 2 => 1, 1 => 3];

    /** relay id => shift id in the first week. Every shift is covered each week. */
    private const RELAY_WEEK_ONE = [1 => 1, 2 => 2, 3 => 3];

    public function run()
    {
        $now = now();

        $this->command->info('Clearing demo tables...');
        $this->reset();

        $this->command->info('Seeding masters...');
        $this->masters($now);

        $this->command->info('Seeding employees...');
        $employees = $this->employees($now);

        $this->command->info('Seeding weekly rotation...');
        $weeks = $this->rotation($now);

        $this->command->info('Seeding attendance...');
        $this->attendance($employees, $weeks, $now);

        $this->command->info('Seeding leaves...');
        $this->leaves($employees, $now);

        $this->summary($weeks);
    }

    /**
     * The attendance table is called attendance_processed on a freshly migrated
     * database and attendance_processeds on the older live one. The model
     * resolves whichever exists, so it is the only safe source for the name.
     */
    private function attendanceTable(): string
    {
        return (new AttendanceProcessed)->getTable();
    }

    /**
     * Everything this seeder owns, cleared so it can be re-run. leave_types,
     * roles and users are deliberately not in the list — those are real masters
     * seeded elsewhere.
     */
    private function reset()
    {
        $tables = [
            $this->attendanceTable(), 'leaves', 'employee_shift_overrides',
            'employee_shift_histories', 'employee_shift_assignments',
            'relay_shift_mappings', 'employee_wages', 'employees', 'relays',
            'shifts', 'holidays', 'working_days', 'designations', 'departments',
            'sites',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function masters(Carbon $now)
    {
        DB::table('sites')->insert([
            ['id' => 1, 'site_name' => 'Dudi Opencast Block', 'address' => 'Dudi, Singrauli', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('departments')->insert([
            ['id' => 1, 'name' => 'Mining Operations', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Workshop',          'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Transport',         'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('designations')->insert([
            ['id' => 1, 'designation_name' => 'Excavator Operator', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'designation_name' => 'Dumper Driver',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'designation_name' => 'Fitter',             'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'designation_name' => 'Helper',             'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'designation_name' => 'Shift Supervisor',   'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach (self::SHIFTS as $id => $shift) {
            DB::table('shifts')->insert($shift + [
                'id' => $id, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('relays')->insert([
            ['id' => 1, 'name' => 'Relay_1', 'is_rotating' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Relay_2', 'is_rotating' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Relay_3', 'is_rotating' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // The control group: never rotates, so its OT baseline is the same all
            // three months and can be compared against the rotating relays.
            ['id' => 4, 'name' => 'General', 'is_rotating' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        foreach ($days as $day) {
            DB::table('working_days')->insert([
                'day' => $day, 'is_working' => $day === 'Sunday' ? 0 : 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // leave_types is a fixed four-row master owned by its own migration, so it
        // is left alone here — only the allowances are opened up enough for the
        // demo leaves to sit inside them.
        DB::table('leave_types')->where('register_group', 'compensatory_rest')->update(['allowed_days' => 4]);
        DB::table('leave_types')->where('register_group', 'medical')->update(['allowed_days' => 7]);
        DB::table('leave_types')->where('register_group', 'other')->update(['allowed_days' => 30]);

        DB::table('holidays')->insert([
            ['holiday_name' => 'Independence Day', 'holiday_date' => '2026-08-15', 'site_id' => 1, 'holiday_type' => 'national', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Two dated wage revisions per category, so a July revision splits the
        // three months and Form B has something to show.
        $wages = [
            'unskilled'      => [[12000, 2000, 75], [13000, 2200, 82]],
            'semi_skilled'   => [[14500, 2400, 90], [15600, 2600, 98]],
            'skilled'        => [[17000, 2800, 110], [18300, 3000, 120]],
            'highly_skilled' => [[21000, 3400, 140], [22600, 3600, 152]],
        ];
        foreach ($wages as $category => $revisions) {
            foreach ([['2026-01-01', $revisions[0]], ['2026-07-01', $revisions[1]]] as [$from, $rates]) {
                DB::table('employee_wages')->insert([
                    'skill_category' => $category,
                    'minimum_basic' => $rates[0], 'dearness_allowance' => $rates[1], 'overtime_rate' => $rates[2],
                    'effective_from' => $from, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    private function employees(Carbon $now): array
    {
        // 3 per rotating relay + 3 on the non-rotating General relay.
        $spec = [
            ['Ramesh',  'Kumar',   1, 'skilled',        1, 1, 'opencast'],
            ['Suresh',  'Yadav',   1, 'semi_skilled',   1, 2, 'opencast'],
            ['Vinod',   'Sahu',    1, 'unskilled',      1, 4, 'surface'],
            ['Mahesh',  'Singh',   2, 'skilled',        1, 1, 'opencast'],
            ['Dinesh',  'Patel',   2, 'semi_skilled',   3, 2, 'opencast'],
            ['Rakesh',  'Verma',   2, 'unskilled',      1, 4, 'surface'],
            ['Ganesh',  'Tiwari',  3, 'highly_skilled', 2, 3, 'underground'],
            ['Naresh',  'Gupta',   3, 'semi_skilled',   3, 2, 'opencast'],
            ['Umesh',   'Mishra',  3, 'unskilled',      1, 4, 'surface'],
            ['Jitendra','Pandey',  4, 'highly_skilled', 2, 5, 'surface'],
            ['Kailash', 'Dubey',   4, 'skilled',        2, 3, 'surface'],
            ['Prakash', 'Sharma',  4, 'semi_skilled',   3, 2, 'surface'],
        ];

        $employees = [];
        foreach ($spec as $i => [$name, $surname, $relayId, $skill, $deptId, $desigId, $place]) {
            $id = $i + 1;
            DB::table('employees')->insert([
                'id' => $id,
                'employee_code' => sprintf('DCM%03d', $id),
                'name' => $name, 'surname' => $surname, 'father_name' => 'Late Shri ' . $surname,
                'dob' => Carbon::parse('1985-01-01')->addDays($i * 137)->toDateString(),
                'gender' => 'male', 'nationality' => 'Indian',
                'mobile' => '9' . str_pad((string) (800000000 + $id * 137), 9, '0', STR_PAD_LEFT),
                'address' => 'Village Dudi, Singrauli, MP',
                'joining_date' => '2025-04-01',
                'employee_type' => 'permanent',
                'department_id' => $deptId, 'designation_id' => $desigId,
                'skill_category' => $skill, 'site_id' => 1,
                'place_of_employment' => $place,
                'relay_id' => $relayId, 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $employees[$id] = ['id' => $id, 'relay_id' => $relayId, 'place' => $place];
        }

        return $employees;
    }

    /**
     * One relay_shift_mappings row per relay per week, for every week in range —
     * the rotation history a month's attendance is priced against. A missing week
     * here is what makes the resolver fall back to a flat 8h day, so the demo
     * deliberately has no gaps.
     *
     * @return array<int,array{start:string,end:string,shifts:array<int,int>}>
     */
    private function rotation(Carbon $now): array
    {
        $weeks = [];
        $shifts = self::RELAY_WEEK_ONE;
        $weekStart = Carbon::parse(self::START);
        $end = Carbon::parse(self::END);

        while ($weekStart->lessThanOrEqualTo($end)) {
            $weekEnd = $weekStart->copy()->addDays(6);

            foreach ($shifts as $relayId => $shiftId) {
                DB::table('relay_shift_mappings')->insert([
                    'relay_id' => $relayId, 'shift_id' => $shiftId,
                    'week_start_date' => $weekStart->toDateString(),
                    'week_end_date' => $weekEnd->toDateString(),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $weeks[] = [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'shifts' => $shifts,
            ];

            $shifts = array_map(function ($shiftId) {
                return self::ROTATION_NEXT[$shiftId];
            }, $shifts);

            $weekStart->addWeek();
        }

        // Legacy assignments, as RotateShiftsCommand's sync leaves them: rotating
        // relays carry only the current week, the General relay spans the range.
        $current = end($weeks);
        foreach (DB::table('employees')->get() as $employee) {
            $isRotating = $employee->relay_id !== 4;
            DB::table('employee_shift_assignments')->insert([
                'employee_id' => $employee->id,
                'shift_id' => $isRotating ? $current['shifts'][$employee->relay_id] : 1,
                'from_date' => $isRotating ? $current['start'] : self::START,
                'to_date' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $weeks;
    }

    /**
     * The shift an employee is rostered on for a date, by the same precedence the
     * resolver applies — relay mapping for rotating relays, otherwise Morning.
     */
    private function shiftForDate(array $employee, string $date, array $weeks): int
    {
        if ($employee['relay_id'] === 4) {
            return 1;
        }

        foreach ($weeks as $week) {
            if ($date >= $week['start'] && $date <= $week['end']) {
                return $week['shifts'][$employee['relay_id']];
            }
        }

        return 1;
    }

    private function attendance(array $employees, array $weeks, Carbon $now)
    {
        // Fixed seed so re-running produces the same figures to compare against.
        mt_srand(20260825);

        $rows = [];
        $end = Carbon::parse(self::END);

        foreach ($employees as $employee) {
            for ($date = Carbon::parse(self::START); $date->lessThanOrEqualTo($end); $date->addDay()) {
                $dateStr = $date->toDateString();
                $shiftId = $this->shiftForDate($employee, $dateStr, $weeks);
                $shift = self::SHIFTS[$shiftId];

                $start = Carbon::parse($dateStr . ' ' . $shift['start_time']);
                $shiftEnd = Carbon::parse($dateStr . ' ' . $shift['end_time']);
                if ($shiftEnd->lessThanOrEqualTo($start)) {
                    $shiftEnd->addDay(); // night shift runs past midnight
                }
                $shiftHours = $start->diffInMinutes($shiftEnd) / 60;

                $roll = mt_rand(1, 100);
                $isSunday = $date->dayOfWeek === Carbon::SUNDAY;

                if ($isSunday && $roll > 12) {
                    // Weekly rest, not worked.
                    $rows[] = $this->row($employee, $dateStr, null, null, null, 0, 'rest_day', $now, 'Weekly rest');
                    continue;
                }

                if (!$isSunday && $roll <= 4) {
                    $rows[] = $this->row($employee, $dateStr, null, null, null, 0, 'absent', $now, null);
                    continue;
                }

                if (!$isSunday && $roll <= 8) {
                    $rows[] = $this->row($employee, $dateStr, null, null, null, 0, 'leave', $now, 'Approved leave');
                    continue;
                }

                // Punched in: a few minutes of jitter, then an overtime bucket.
                $checkIn = $start->copy()->addMinutes(mt_rand(-5, 10));

                $status = (!$isSunday && $roll <= 12) ? 'half_day' : ($isSunday ? 'rest_day' : 'present');

                if ($status === 'half_day') {
                    $checkOut = $checkIn->copy()->addMinutes((int) round($shiftHours * 30));
                } else {
                    $otBucket = mt_rand(1, 100);
                    $otHours = $otBucket <= 58 ? 0 : ($otBucket <= 80 ? 1 : ($otBucket <= 93 ? 2 : 3));
                    $checkOut = $shiftEnd->copy()->addHours($otHours)->addMinutes(mt_rand(0, 10));
                }

                $workingHours = round($checkIn->diffInMinutes($checkOut) / 60, 2);

                // shift_id is left NULL on most rows, as on live, so readers have to
                // resolve the roster. A tenth carry it, matching the roster.
                $storedShiftId = mt_rand(1, 10) === 1 ? $shiftId : null;

                $rows[] = $this->row(
                    $employee, $dateStr, $storedShiftId, $checkIn, $checkOut, $workingHours, $status, $now,
                    $status === 'rest_day' ? 'Worked on rest day' : null
                );
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($this->attendanceTable())->insert($chunk);
        }

        $this->command->line('  ' . count($rows) . ' attendance rows');
    }

    private function row(array $employee, string $date, $shiftId, $in, $out, $hours, string $status, Carbon $now, $remarks): array
    {
        return [
            'employee_id' => $employee['id'],
            'shift_id' => $shiftId,
            'place_of_work' => $employee['place'],
            'date' => $date,
            'check_in' => $in ? $in->toDateTimeString() : null,
            'check_out' => $out ? $out->toDateTimeString() : null,
            'working_hours' => $hours,
            'late_minutes' => 0,
            'early_exit_minutes' => 0,
            'attendance_status' => $status,
            'remarks' => $remarks,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Approved leaves matching a sample of the days attendance already marks as
     * leave, so the leave register and the attendance module agree.
     */
    private function leaves(array $employees, Carbon $now)
    {
        $approver = DB::table('users')->value('id');

        $leaveDays = DB::table($this->attendanceTable())
            ->where('attendance_status', 'leave')
            ->orderBy('employee_id')->orderBy('date')
            ->get(['employee_id', 'date'])
            ->groupBy('employee_id')
            ->map(function ($group) {
                return $group->take(3);
            });

        $rows = [];
        foreach ($leaveDays as $employeeId => $days) {
            foreach ($days as $i => $day) {
                $rows[] = [
                    'employee_id' => $employeeId,
                    'leave_type_id' => [2, 3, 4][$i % 3],
                    'from_date' => $day->date,
                    'to_date' => $day->date,
                    'reason' => 'Seeded demo leave',
                    'status' => 'approved',
                    'approved_by' => $approver,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        if ($rows) {
            DB::table('leaves')->insert($rows);
        }

        $this->command->line('  ' . count($rows) . ' approved leaves');
    }

    private function summary(array $weeks)
    {
        $this->command->info('');
        $this->command->info('Shift lengths (the OT baselines):');
        foreach (self::SHIFTS as $id => $shift) {
            $start = Carbon::parse($shift['start_time']);
            $end = Carbon::parse($shift['end_time']);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }
            $this->command->line(sprintf(
                '  %d  %-14s %s - %s  = %sh',
                $id, $shift['shift_name'], $shift['start_time'], $shift['end_time'],
                round($start->diffInMinutes($end) / 60, 2)
            ));
        }

        $this->command->info('');
        $this->command->info('Weekly rotation (relay => shift):');
        foreach ($weeks as $week) {
            $this->command->line(sprintf(
                '  %s .. %s   R1=%d  R2=%d  R3=%d',
                $week['start'], $week['end'],
                $week['shifts'][1], $week['shifts'][2], $week['shifts'][3]
            ));
        }
    }
}
