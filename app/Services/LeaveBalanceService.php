<?php

namespace App\Services;

use App\Models\AttendanceProcessed;
use App\Models\Employee;
use App\Models\LeaveRegisterReport;
use App\Models\LeaveRegisterReportRow;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computes the Form E leave ledger. Nothing here is stored.
 *
 *   Opening Balance  = always 0 — leave is not carried forward, every year
 *                      starts fresh and any unused balance lapses
 *   Added            = the block's annual quota from the leave_types master —
 *                      every block including Compensatory Rest, whose quota the
 *                      admin sets like any other
 *   Rest Not Allowed = weeks the employee worked 6+ days with no rest day.
 *                      Reported for column 6 only; it no longer feeds Added.
 *   Availed          = approved rows in `leaves` for that block, clipped to the year
 *   Closing Balance  = Added - Availed, floored at 0
 *
 * Because nothing carries forward, each year is independent: a year is computed
 * from three grouped queries against that year alone, with no walk over history.
 */
class LeaveBalanceService
{
    /**
     * How many rest days in a month are paid.
     *
     * Replaces the old per-employee employee_payrolls.rest_days setting, which
     * was reached through an Employee::rest_days accessor that no longer exists.
     * One establishment-wide number so payroll cannot vary person to person.
     *
     * Note this is a fresh cap each month, not a yearly budget: a worker with
     * rest days in every month can be paid up to 4 x 12 across the year.
     */
    const MONTHLY_PAID_REST_DAYS = 4;

    /**
     * The monthly paid-rest-day cap. Kept as a method so it can later read from
     * the leave master or a settings table without touching the call sites.
     */
    public static function monthlyPaidRestDays(): int
    {
        return self::MONTHLY_PAID_REST_DAYS;
    }

    /**
     * Full ledger for a set of employees in one year.
     *
     * @param  \Illuminate\Support\Collection  $employees  Employee models
     * @return array  [employee_id => ['days_worked' => float, 'groups' => [group => [...]]]]
     */
    public function ledgerFor($employees, int $year): array
    {
        $employees = collect($employees);

        if ($employees->isEmpty()) {
            return [];
        }

        $employeeIds = $employees->pluck('id')->all();

        $availed = $this->availedMap($employeeIds, $year);
        $restDenied = $this->restNotAllowedMap($employeeIds, $year);
        $worked = $this->daysWorkedMap($employeeIds, $year);

        $leaveTypes = LeaveType::onRegister()->get()->keyBy('register_group');

        $ledger = [];

        foreach ($employees as $employee) {
            $groups = [];

            // Someone who had not joined yet, or who had already left, earns no
            // quota for that year. The register listing filters these people out
            // entirely, but a direct lookup by employee id can still ask for one.
            $employed = $this->employedDuring($employee, $year);

            foreach (LeaveType::REGISTER_GROUPS as $group => $label) {
                $leaveType = $leaveTypes->get($group);
                $typeId = $leaveType ? $leaveType->id : null;

                $restNotAllowed = (float) ($restDenied[$employee->id] ?? 0);

                // Every block, compensatory rest included, is credited from the
                // annual quota the admin sets on the master.
                $added = (float) ($leaveType->allowed_days ?? 0);

                if (! $employed) {
                    $added = 0.0;
                }

                $availedDays = $typeId
                    ? (float) ($availed[$employee->id][$typeId] ?? 0)
                    : 0.0;

                // Nothing is carried in from last year, so the opening balance
                // is always zero and anything unused simply lapses.
                $opening = 0.0;

                // A worker cannot carry a negative entitlement, so the register
                // floors the closing balance at zero.
                $closingBalance = max(0.0, round($opening + $added - $availedDays, 1));

                $groups[$group] = [
                    'register_group' => $group,
                    'label' => $label,
                    'leave_type_id' => $typeId,
                    'opening_balance' => $opening,
                    'added' => round($added, 1),
                    'availed' => round($availedDays, 1),
                    'closing_balance' => $closingBalance,
                    // Form E prints column 6 for Compensatory Rest only.
                    'rest_not_allowed' => $group === 'compensatory_rest' ? $restNotAllowed : null,
                ];
            }

            $ledger[$employee->id] = [
                'days_worked' => (float) ($worked[$employee->id] ?? 0),
                'groups' => $groups,
            ];
        }

        return $ledger;
    }

    /**
     * Approved leave days per employee, per leave type, for one year.
     *
     * A leave spanning a year boundary (28 Dec - 3 Jan) is clipped to the year
     * asked for, so the two years' registers add up to the leave's real length.
     *
     * @return array  [employee_id => [leave_type_id => float]]
     */
    public function availedMap(array $employeeIds, int $year): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $start = Carbon::create($year, 1, 1)->format('Y-m-d');
        $end = Carbon::create($year, 12, 31)->format('Y-m-d');

        $rows = DB::table('leaves')
            ->selectRaw(
                'employee_id, leave_type_id, SUM(DATEDIFF(LEAST(to_date, ?), GREATEST(from_date, ?)) + 1) AS days',
                [$end, $start]
            )
            ->where('status', 'approved')
            ->whereNotNull('leave_type_id')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('from_date', '<=', $end)
            ->whereDate('to_date', '>=', $start)
            ->groupBy('employee_id', 'leave_type_id')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[$row->employee_id][$row->leave_type_id] = (float) $row->days;
        }

        return $map;
    }

    /**
     * Weekly rests denied — Form E column 6, and the source of compensatory rest.
     *
     * The Mines Act gives one rest day per week. A week where the employee has
     * no day marked `rest_day` and worked six or more days is a denied rest.
     * Weeks are grouped within the calendar year, so a week straddling 31 Dec
     * splits into two part-weeks that cannot reach the six-day threshold.
     *
     * @return array  [employee_id => int]
     */
    public function restNotAllowedMap(array $employeeIds, int $year): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $table = (new AttendanceProcessed)->getTable();

        $rows = DB::table($table)
            ->selectRaw('employee_id, YEARWEEK(date, 1) AS wk')
            ->selectRaw("SUM(CASE WHEN attendance_status = 'rest_day' THEN 1 ELSE 0 END) AS rest_days")
            ->selectRaw("SUM(CASE WHEN attendance_status IN ('present', 'half_day') THEN 1 ELSE 0 END) AS worked_days")
            ->whereIn('employee_id', $employeeIds)
            ->whereYear('date', $year)
            ->groupBy('employee_id', 'wk')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            if ((int) $row->rest_days === 0 && (int) $row->worked_days >= 6) {
                $map[$row->employee_id] = ($map[$row->employee_id] ?? 0) + 1;
            }
        }

        return $map;
    }

    /**
     * Days actually worked — Form E column 3. Half days count as half.
     *
     * @return array  [employee_id => float]
     */
    public function daysWorkedMap(array $employeeIds, int $year): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $table = (new AttendanceProcessed)->getTable();

        $rows = DB::table($table)
            ->selectRaw("employee_id, SUM(CASE WHEN attendance_status = 'present' THEN 1 WHEN attendance_status = 'half_day' THEN 0.5 ELSE 0 END) AS days")
            ->whereIn('employee_id', $employeeIds)
            ->whereYear('date', $year)
            ->groupBy('employee_id')
            ->get();

        return $rows->mapWithKeys(function ($row) {
            return [$row->employee_id => (float) $row->days];
        })->all();
    }

    /**
     * The canonical set of employees the register covers for a year.
     *
     * Shared by the live preview and by report generation so a generated
     * report cannot silently cover a different set of people than the screen
     * it was generated from.
     *
     * @param  array  $filters  site_id, department_id, designation_id, search,
     *                          employee_status ('active'|'all')
     */
    public function registerEmployeeQuery(int $year, array $filters = [])
    {
        $query = Employee::with(['department', 'designation', 'site'])
            ->orderBy('employee_code')
            ->orderBy('id');

        // Register rows are the establishment's own workers, matching the
        // attendance register's exclusions.
        $query->whereDoesntHave('roleUser.user.roles', function ($q) {
            $q->whereIn('name', ['Super Admin', 'CEO']);
        });

        foreach (['site_id', 'department_id', 'designation_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('surname', 'LIKE', "%{$search}%")
                    ->orWhere('employee_code', 'LIKE', "%{$search}%");
            });
        }

        // Anyone who had not joined yet, or who had already left, gets no row.
        $status = strtolower((string) ($filters['employee_status'] ?? 'active'));

        if ($status !== 'all') {
            $query->whereDate('joining_date', '<=', $year . '-12-31')
                ->where(function ($q) use ($year) {
                    $q->whereNull('date_of_exit')
                        ->orWhereDate('date_of_exit', '>=', $year . '-01-01');
                });
        }

        return $query;
    }

    /**
     * Was the employee on the rolls at any point during the year?
     *
     * An employee with no joining date on record is assumed to be, since the
     * alternative is silently zeroing a real worker's entitlement.
     */
    protected function employedDuring($employee, int $year): bool
    {
        if ($employee->joining_date && Carbon::parse($employee->joining_date)->year > $year) {
            return false;
        }

        if ($employee->date_of_exit && Carbon::parse($employee->date_of_exit)->year < $year) {
            return false;
        }

        return true;
    }

    /**
     * Freeze the year's register: compute every row once and store it.
     *
     * Covers the whole establishment — a statutory register is not filtered by
     * site or search.
     *
     * A year that already has a report is replaced in place: the same row is
     * reused so the list never shows duplicates, its old detail rows are
     * dropped, and `version` records how many times it has been redone. The
     * controller confirms with the caller before this is reached.
     */
    public function generateReport(int $year, ?int $userId = null, ?string $remarks = null): LeaveRegisterReport
    {
        $employees = $this->registerEmployeeQuery($year)->get();
        $ledger = $this->ledgerFor($employees, $year);

        // Quotas are not versioned, so record what produced these numbers.
        $leaveTypeSnapshot = LeaveType::onRegister()->get()
            ->map(function ($type) {
                return [
                    'register_group' => $type->register_group,
                    'name' => $type->name,
                    'leave_category' => $type->leave_category,
                    'allowed_days' => $type->allowed_days,
                    'is_active' => (bool) $type->is_active,
                ];
            })->values()->all();

        return DB::transaction(function () use ($year, $userId, $remarks, $employees, $ledger, $leaveTypeSnapshot) {
            $existing = LeaveRegisterReport::forYear($year);

            $attributes = [
                'year' => $year,
                'generated_by' => $userId,
                'generated_at' => now(),
                'employee_count' => $employees->count(),
                'leave_type_snapshot' => $leaveTypeSnapshot,
                'remarks' => $remarks,
            ];

            if ($existing) {
                // Reuse the row so the year keeps one stable id, and clear the
                // old detail rows rather than letting both generations coexist.
                $existing->rows()->delete();
                $existing->update($attributes + ['version' => $existing->version + 1]);
                $report = $existing;
            } else {
                $report = LeaveRegisterReport::create($attributes + ['version' => 1]);
            }

            $serial = 1;
            $now = now();
            $rows = [];

            foreach ($employees as $employee) {
                $entry = $ledger[$employee->id] ?? ['days_worked' => 0.0, 'groups' => []];

                $row = [
                    'report_id' => $report->id,
                    'employee_id' => $employee->id,
                    'serial_no' => $serial++,
                    'employee_code' => $employee->employee_code,
                    'employee_name' => trim($employee->name . ' ' . $employee->surname),
                    'days_worked' => $entry['days_worked'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                foreach ($entry['groups'] as $group => $figures) {
                    $prefix = LeaveRegisterReportRow::GROUP_PREFIX[$group];

                    $row[$prefix . '_opening'] = $figures['opening_balance'];
                    $row[$prefix . '_added'] = $figures['added'];
                    $row[$prefix . '_availed'] = $figures['availed'];
                    $row[$prefix . '_closing'] = $figures['closing_balance'];

                    if ($group === 'compensatory_rest') {
                        $row['comp_rest_not_allowed'] = $figures['rest_not_allowed'] ?? 0;
                    }
                }

                $rows[] = $row;
            }

            // Chunked so a large establishment does not build one oversized
            // insert statement.
            foreach (array_chunk($rows, 500) as $chunk) {
                LeaveRegisterReportRow::insert($chunk);
            }

            return $report->fresh();
        });
    }

    /**
     * Convenience wrapper for a single employee.
     */
    public function ledgerForEmployee(Employee $employee, int $year): array
    {
        $ledger = $this->ledgerFor(collect([$employee]), $year);

        return $ledger[$employee->id] ?? ['days_worked' => 0.0, 'groups' => []];
    }
}
