<?php

namespace App\Services;

use App\Models\AttendanceProcessed;
use App\Models\Employee;
use App\Models\EmployeeWage;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Shift;
use App\Models\WageRegisterReport;
use App\Models\WageRegisterReportRow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds Form B (wage register) lines for a month.
 *
 * The attendance, leave, holiday and fixed-deduction figures are derived the
 * same way PayrollController does, so a register and the payroll module agree
 * on what a month cost. What Form B adds is the split between Basic (col 6) and
 * Dearness Allowance (col 8), which payroll keeps merged in `basic_salary`.
 */
class WageRegisterService
{
    /**
     * A month is priced at monthly pay divided by that month's actual length,
     * not a flat 30. This is the divisor PayrollController uses, so the register
     * and the payroll module agree on what a day is worth.
     *
     * The trade-off is that the daily rate drifts with the calendar — a day in
     * February is worth more than a day in August — which is why a flat 30 was
     * used first. The client chose reconciliation with payroll over a stable
     * daily rate.
     */

    /**
     * Employees who belong on the register for a month: active, and already
     * joined by the time the month ended.
     */
    public function registerEmployeeQuery(int $month, int $year, array $filters = [])
    {
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth();

        $query = Employee::with(['activePayroll', 'department'])
            ->where('is_active', true)
            ->whereDate('joining_date', '<=', $monthEnd);

        if (!empty($filters['site_id'])) {
            $query->where('site_id', $filters['site_id']);
        }

        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('surname', 'LIKE', "%{$search}%")
                    ->orWhere('employee_code', 'LIKE', "%{$search}%");
            });
        }

        return $query->orderBy('employee_code')->orderBy('id');
    }

    /**
     * A Form B line per employee, keyed by employee id. Nothing is persisted —
     * generateReport() writes these out, and the preview renders them directly.
     */
    public function linesFor($employees, int $month, int $year): array
    {
        $employees = collect($employees);

        if ($employees->isEmpty()) {
            return [];
        }

        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $daysInMonth = $monthStart->daysInMonth;
        $ids = $employees->pluck('id')->all();

        $attendance = $this->attendanceSummary($ids, $month, $year);
        $leaves = $this->leaveSummary($ids, $monthStart, $monthEnd);
        // Recoveries are capped at a share of each employee's earnings and
        // carry into later months, so they cannot be summed up front — the
        // figure depends on a gross that is only known inside the loop.
        $recoveryService = app(LoanRecoveryService::class);

        $holidays = $this->holidayCounts($month, $year);
        $overtime = $this->overtimeSummary($ids, $month, $year);

        // The rate revision in force at month end prices the whole month.
        $wages = EmployeeWage::effectiveSet($monthEnd->toDateString());

        $restDayCap = LeaveBalanceService::monthlyPaidRestDays();

        $lines = [];

        foreach ($employees as $employee) {
            $att = $attendance->get($employee->id);
            $leave = $leaves[$employee->id] ?? ['paid' => 0, 'unpaid' => 0];

            $presentDays = $att ? (int) $att->present_days : 0;
            $halfDays = $att ? (int) $att->half_days : 0;
            $restDays = $att ? (int) $att->rest_days : 0;

            $siteHolidays = ($holidays['general'] ?? 0) + ($holidays['sites'][$employee->site_id] ?? 0);
            $paidLeaveDays = $leave['paid'] + min($restDays, $restDayCap);

            // Unmarked days count as absent, matching PayrollController.
            $effectiveAbsent = max(0, $daysInMonth - $presentDays - $halfDays - $paidLeaveDays - $siteHolidays);

            // Column 4. Absence is priced here by shortening the month rather
            // than as a separate deduction line, so it must not also appear
            // among columns 13-19 or it would be charged twice.
            $daysWorked = round(max(0, $daysInMonth - ($effectiveAbsent + ($halfDays * 0.5))), 2);

            $payroll = $employee->activePayroll;
            $wage = $wages[$employee->skill_category] ?? null;
            $overtimeRate = $wage ? (float) $wage->overtime_rate : 0.0;

            // The employee's monthly rate, from employee_payrolls rather than the
            // wage master: someone may be paid above the statutory minimum, and a
            // wage register has to show what is really paid.
            $monthlyPay = (float) optional($payroll)->basic_salary;

            // Columns 6 and 8 are the monthly figures, NOT pro-rated — they are
            // the rate the month is priced at. basic_salary is minimum_basic + DA
            // merged, so the wage master supplies only the proportion to split it
            // by. With no skill category or rate, the whole amount goes to Basic
            // and DA prints blank.
            [$basic, $dearnessAmount] = $this->splitBasicAndDearness($monthlyPay, $wage);

            // Days not worked are priced here and charged as a deduction below,
            // not by shrinking the gross. Column 12 shows the full monthly
            // entitlement; what the employee actually takes home is column 21.
            $unworkedDays = max(0, $daysInMonth - $daysWorked);
            $absence = round(($monthlyPay / $daysInMonth) * $unworkedDays, 2);

            // Column 3, the daily rate, is not calculated for now and prints
            // blank. Re-enabling it is this line:
            //
            // $rateOfWage = round($monthlyPay / $daysInMonth, 2);
            $rateOfWage = null;

            // Column 5: hours punched beyond the length of the assigned shift.
            $overtimeHours = round($overtime[$employee->id] ?? 0.0, 2);
            $overtimePayment = round($overtimeHours * $overtimeRate, 2);

            $pf = optional($payroll)->pf_applicable ? (float) $payroll->pf_amount : 0.0;
            $mess = optional($payroll)->mess_deduction_applicable ? (float) $payroll->mess_deduction_amount : 0.0;
            $other = optional($payroll)->other_deduction_appliacble ? (float) $payroll->other_deduction : 0.0;

            // Columns 3, 7, 10, 11, 14-17, 19 and 22-25 are not calculated and
            // stay null so the printed form shows a blank, not a false 0. Every
            // figure that does exist in employee_payrolls — basic salary, PF,
            // mess and other — is used, plus penalty from the penalties table.
            //
            // Column 14 (ESIC) is deliberately not derived: employee_payrolls
            // carries esic_ip_number but no contribution amount or wage ceiling,
            // so there is nothing to compute it from yet.
            // Column 12 is the sum of the earnings columns as printed, so the
            // form adds up left to right.
            $totalEarnings = round($basic + ($dearnessAmount ?? 0) + $overtimePayment, 2);

            // Column 19. Capped at a share of column 12 and carried forward,
            // so it has to run once earnings are known. Column 12 equals the
            // gross PayrollController computes — basic_salary already merges
            // minimum basic and DA, and the split above only redistributes it
            // across columns — so both documents cap against the same figure
            // and cannot disagree.
            //
            // Planned, never applied: building the register must not write
            // installment rows. Payroll generation owns that.
            $penalty = $recoveryService->planEmployeeRecoveries(
                $employee->id,
                $totalEarnings,
                $month,
                $year
            )['total'];

            // Absence is deliberately not part of column 20. It is not a
            // deduction the employer withheld — it is pay never earned — and it
            // gets no column on Form B, so it comes off the net directly.
            $totalDeductions = round($pf + $other + $mess + $penalty, 2);

            $lines[$employee->id] = [
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'employee_name' => trim($employee->name . ' ' . $employee->surname),
                'skill_category' => $employee->skill_category,

                'rate_of_wage' => $rateOfWage,
                'days_worked' => $daysWorked,
                'overtime_hours' => $overtimeHours,
                'basic' => $basic,
                'special_basic' => null,
                'dearness_allowance' => $dearnessAmount,
                'overtime_payment' => $overtimePayment,
                'hra' => null,
                'other_earnings' => null,
                'total_earnings' => $totalEarnings,

                'pf_deduction' => $pf,
                'esic_deduction' => null,
                'society_deduction' => null,
                'income_tax' => null,
                'insurance' => null,
                'other_deduction' => $other,
                'mess_deduction' => $mess,
                'penalty_deduction' => $penalty,
                'absence_deduction' => $absence,
                'recoveries' => null,
                'total_deductions' => $totalDeductions,

                // Column 21 = column 12 minus column 20 minus the wages for days
                // not worked. Absence has no column of its own on the form, so
                // the net will not reconcile to 12 - 20 by itself whenever an
                // employee was short of a full month.
                //
                // Floored at 0 — nothing is ever recovered from an employee via
                // this register, so a shortfall is dropped rather than carried.
                // WageRegisterReportRow::unrecoveredDeduction() recovers the gap.
                'net_payment' => round(max(0, $totalEarnings - $totalDeductions - $absence), 2),
                'employer_pf_share' => null,
                'payment_reference' => null,
                'payment_date' => null,
                'remarks' => null,
            ];
        }

        return $lines;
    }

    /**
     * Freeze a month. Replaces an existing report for the same month in place,
     * bumping its version, so the listing never fills with duplicates.
     */
    public function generateReport(int $month, int $year, ?int $userId = null, ?string $remarks = null): WageRegisterReport
    {
        $employees = $this->registerEmployeeQuery($month, $year)->get();
        $lines = $this->linesFor($employees, $month, $year);

        return DB::transaction(function () use ($month, $year, $userId, $remarks, $employees, $lines) {
            $existing = WageRegisterReport::forMonth($month, $year);

            $report = $existing ?: new WageRegisterReport(['month' => $month, 'year' => $year, 'version' => 0]);

            $report->version = (int) $report->version + 1;
            $report->generated_by = $userId;
            $report->generated_at = now();
            $report->employee_count = count($lines);
            $report->total_earnings = round(array_sum(array_column($lines, 'total_earnings')), 2);
            $report->total_deductions = round(array_sum(array_column($lines, 'total_deductions')), 2);
            $report->total_net = round(array_sum(array_column($lines, 'net_payment')), 2);
            $report->wage_rate_snapshot = $this->rateSnapshot($year, $month);
            $report->remarks = $remarks;
            $report->save();

            // A regenerated month is rebuilt from scratch: serial numbers shift
            // when the workforce changes, so patching rows in place would leave
            // the register mis-numbered.
            $report->rows()->delete();

            $serial = 1;
            $now = now();
            $insert = [];

            foreach ($employees as $employee) {
                if (!isset($lines[$employee->id])) {
                    continue;
                }

                $insert[] = array_merge($lines[$employee->id], [
                    'report_id' => $report->id,
                    'serial_no' => $serial++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (array_chunk($insert, 500) as $chunk) {
                WageRegisterReportRow::insert($chunk);
            }

            return $report->fresh();
        });
    }

    /**
     * Splits a monthly rate into Form B's Basic (col 6) and Dearness Allowance
     * (col 8) using the wage master's proportion for that skill category.
     *
     * The master decides only the ratio, never the amount — the amount is what
     * the employee is actually paid. With no rate to go on, or a rate with no
     * DA, the whole amount is Basic and DA is left null so the column prints
     * blank rather than claiming a DA of 0 was paid. Basic is taken as the
     * remainder so the two always add back to exactly the amount passed in.
     *
     * @return array{0: float, 1: float|null}
     */
    protected function splitBasicAndDearness(float $amount, $wage): array
    {
        if (!$wage) {
            return [$amount, null];
        }

        $minimumBasic = (float) $wage->minimum_basic;
        $dearness = (float) $wage->dearness_allowance;
        $total = $minimumBasic + $dearness;

        if ($total <= 0 || $dearness <= 0) {
            return [$amount, null];
        }

        $dearnessShare = round($amount * ($dearness / $total), 2);

        return [round($amount - $dearnessShare, 2), $dearnessShare];
    }

    /**
     * The Form B header: the four skill-category rates in force at month end.
     */
    public function rateSnapshot(int $year, int $month): array
    {
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $snapshot = [];

        foreach (EmployeeWage::effectiveSet($monthEnd) as $category => $wage) {
            $snapshot[$category] = $wage ? [
                'minimum_basic' => (float) $wage->minimum_basic,
                'dearness_allowance' => (float) $wage->dearness_allowance,
                'overtime_rate' => (float) $wage->overtime_rate,
                'effective_from' => $wage->effective_from->toDateString(),
            ] : null;
        }

        return $snapshot;
    }

    /**
     * Overtime hours per employee for the month.
     *
     * A day's overtime is how much longer the employee was punched in than the
     * shift they were assigned to: (check_out - check_in) - (shift end - shift
     * start). Measured against the shift's own clock rather than its
     * `minimum_working_hours`, which is a separate figure and does not always
     * agree with the times.
     *
     * Days with no punch pair are skipped rather than counted as zero overtime —
     * an unrecorded day says nothing about whether extra hours were worked.
     *
     * @return array<int,float> employee id => hours
     */
    public function overtimeSummary(array $ids, int $month, int $year): array
    {
        if (empty($ids)) {
            return [];
        }

        $shifts = Shift::all()->keyBy('id');
        $lengths = [];

        foreach ($shifts as $shift) {
            $start = Carbon::parse($shift->start_time);
            $end = Carbon::parse($shift->end_time);

            // A shift finishing at or before it starts runs past midnight.
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            $lengths[$shift->id] = $start->diffInMinutes($end) / 60;
        }

        $rows = AttendanceProcessed::whereIn('employee_id', $ids)
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->whereNotNull('check_in')
            ->whereNotNull('check_out')
            ->get(['employee_id', 'shift_id', 'check_in', 'check_out']);

        $hours = [];

        foreach ($rows as $row) {
            $shiftLength = $lengths[$row->shift_id] ?? null;

            if ($shiftLength === null || $shiftLength <= 0) {
                continue;
            }

            $in = Carbon::parse($row->check_in);
            $out = Carbon::parse($row->check_out);

            if ($out->lessThanOrEqualTo($in)) {
                $out->addDay();
            }

            $worked = $in->diffInMinutes($out) / 60;
            $extra = $worked - $shiftLength;

            if ($extra > 0) {
                $hours[$row->employee_id] = ($hours[$row->employee_id] ?? 0) + $extra;
            }
        }

        return $hours;
    }

    protected function attendanceSummary(array $ids, int $month, int $year)
    {
        return AttendanceProcessed::whereIn('employee_id', $ids)
            ->whereMonth('date', $month)->whereYear('date', $year)
            ->groupBy('employee_id')
            ->selectRaw('
                employee_id,
                SUM(CASE WHEN attendance_status = "present" THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN attendance_status = "half_day" THEN 1 ELSE 0 END) as half_days,
                SUM(CASE WHEN attendance_status = "rest_day" THEN 1 ELSE 0 END) as rest_days
            ')
            ->get()
            ->keyBy('employee_id');
    }

    /**
     * Approved leave days falling inside the month, split paid vs unpaid. A
     * leave spanning a month boundary is clipped to the part inside it.
     */
    protected function leaveSummary(array $ids, Carbon $monthStart, Carbon $monthEnd): array
    {
        $leaves = Leave::whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('from_date', [$monthStart, $monthEnd])
                    ->orWhereBetween('to_date', [$monthStart, $monthEnd])
                    ->orWhere(function ($q2) use ($monthStart, $monthEnd) {
                        // Fully spans the month.
                        $q2->where('from_date', '<', $monthStart)
                            ->where('to_date', '>', $monthEnd);
                    });
            })
            ->with('leaveType')
            ->get();

        $summary = [];

        foreach ($leaves as $leave) {
            $from = Carbon::parse($leave->from_date)->max($monthStart);
            $to = Carbon::parse($leave->to_date)->min($monthEnd);
            $days = $from->diffInDays($to) + 1;

            $bucket = (optional($leave->leaveType)->leave_category === 'paid') ? 'paid' : 'unpaid';

            $summary[$leave->employee_id]['paid'] = $summary[$leave->employee_id]['paid'] ?? 0;
            $summary[$leave->employee_id]['unpaid'] = $summary[$leave->employee_id]['unpaid'] ?? 0;
            $summary[$leave->employee_id][$bucket] += $days;
        }

        return $summary;
    }

    /**
     * Holidays split into establishment-wide and per site, so each employee is
     * credited only with the ones that apply to them.
     */
    protected function holidayCounts(int $month, int $year): array
    {
        $holidays = Holiday::whereMonth('holiday_date', $month)
            ->whereYear('holiday_date', $year)
            ->where('is_active', true)
            ->get();

        return [
            'general' => $holidays->whereNull('site_id')->count(),
            'sites' => $holidays->whereNotNull('site_id')
                ->groupBy('site_id')
                ->map->count()
                ->all(),
        ];
    }
}
