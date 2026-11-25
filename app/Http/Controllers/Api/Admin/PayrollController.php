<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\Employee;
use App\Models\AttendanceProcessed;
use App\Models\Leave;
use App\Models\Holiday;
use App\Http\Resources\PayrollResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PayrollController extends Controller
{
    /**
     * Display a paginated listing of payroll records.
     *
     * Filters: month, year, site_id, department_id, search (name/code)
     */
    /**
     * An amount as it leaves the API: a float carrying at most two decimals.
     *
     * Every money figure is passed through this on the way out. The columns
     * behind them are decimal(12,2), so the paise were always meant to be
     * there — they were being lost to whole-rupee rounding, which reported a
     * leave deduction of 11.60 as 12.
     *
     * Rounding stays at the edge: the arithmetic above runs at full precision
     * so a chain of deductions does not accumulate rounding error.
     */
    private function money($value): float
    {
        return round((float) $value, 2);
    }

    public function index(Request $request)
    {
        try {
            $request->validate([
                'month' => 'nullable|integer|between:1,12',
                'year' => 'nullable|integer|min:2020',
            ]);

            $month = (int) ($request->month ?? now()->month);
            $year = (int) ($request->year ?? now()->year);
            $limit = $request->input('limit', 10);

            $endDate = Carbon::create($year, $month, 1)->endOfMonth();

            // ── Build employee query with filters ──
            $employeeQuery = Employee::with(['department', 'designation', 'site', 'activePayroll'])
                ->where('is_active', true)
                ->whereDate('joining_date', '<=', $endDate->format('Y-m-d'));

            if ($request->filled('site_id')) {
                $employeeQuery->where('site_id', $request->site_id);
            }

            if ($request->filled('department_id')) {
                $employeeQuery->where('department_id', $request->department_id);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $employeeQuery->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('employee_code', 'LIKE', "%{$search}%");
                });
            }

            $employees = $employeeQuery->orderBy('name')->paginate($limit);

            // ── Calculate working days in the month ──
            $daysInMonth = Carbon::create($year, $month)->daysInMonth;

            // Count holidays for the month (site-specific ones handled per-employee below)
            $generalHolidays = Holiday::whereMonth('holiday_date', $month)
                ->whereYear('holiday_date', $year)
                ->where('is_active', true)
                ->whereNull('site_id')
                ->count();

            // ── Gather data for each employee ──
            $employeeIds = $employees->pluck('id');

            // Attendance counts per employee
            $attendanceCounts = AttendanceProcessed::whereIn('employee_id', $employeeIds)
                ->whereMonth('date', $month)
                ->whereYear('date', $year)
                ->selectRaw('employee_id,
                    SUM(CASE WHEN attendance_status = "present" THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN attendance_status = "absent" THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN attendance_status = "half_day" THEN 1 ELSE 0 END) as half_days,
                    SUM(CASE WHEN attendance_status = "leave" THEN 1 ELSE 0 END) as leave_days,
                    SUM(CASE WHEN attendance_status = "rest_day" THEN 1 ELSE 0 END) as rest_days
                ')
                ->groupBy('employee_id')
                ->get()
                ->keyBy('employee_id');

            // Approved leave days per employee, counted as distinct calendar
            // dates and net of days attendance already pays for. Summing each
            // leave's length double-paid any day two leaves overlapped on, and
            // any day filed as leave that attendance had marked present.
            $leaveSummary = \App\Services\LeaveBalanceService::monthlyLeaveDays(
                $employeeIds->all(),
                $month,
                $year
            );

            // Recoveries are capped at a percentage of each employee's gross
            // and carry into later months, so the amount cannot be summed up
            // front — it is resolved per employee once gross is known below.
            $recoveryService = app(\App\Services\LoanRecoveryService::class);

            // Site-specific holidays
            $siteHolidays = Holiday::whereMonth('holiday_date', $month)
                ->whereYear('holiday_date', $year)
                ->where('is_active', true)
                ->whereNotNull('site_id')
                ->selectRaw('site_id, COUNT(*) as count')
                ->groupBy('site_id')
                ->pluck('count', 'site_id');

            // Existing payroll records
            $existingPayrolls = Payroll::whereIn('employee_id', $employeeIds)
                ->where('month', $month)
                ->where('year', $year)
                ->get()
                ->keyBy('employee_id');

            // Overtime hours and the rate they are paid at. Shared with the wage
            // register so both modules count the same hours.
            $overtimeHoursMap = app(\App\Services\WageRegisterService::class)
                ->overtimeSummary($employeeIds->all(), $month, $year);
            $overtimeRates = \App\Models\EmployeeWage::effectiveSet(
                Carbon::create($year, $month, 1)->endOfMonth()->toDateString()
            );

            // ── Build result collection ──
            $result = $employees->getCollection()->map(function ($employee) use ($attendanceCounts, $leaveSummary, $recoveryService, $generalHolidays, $siteHolidays, $daysInMonth, $existingPayrolls, $month, $year, $overtimeHoursMap, $overtimeRates) {
                $att = $attendanceCounts->get($employee->id);
                $empLeave = $leaveSummary[$employee->id] ?? ['paid' => 0, 'unpaid' => 0];

                $holidays = $generalHolidays + ($siteHolidays[$employee->site_id] ?? 0);
                $activePayroll = $employee->activePayroll;
                $restDaysSetting = \App\Services\LeaveBalanceService::monthlyPaidRestDays();

                $presentDays = $att ? (int) $att->present_days : 0;
                $absentDays = $att ? (int) $att->absent_days : 0;
                $halfDays = $att ? (int) $att->half_days : 0;
                $restDays = $att ? (int) $att->rest_days : 0;
                $paidRestDays = min($restDays, $restDaysSetting);
                $paidLeaveDays = $empLeave['paid'] + $paidRestDays; // rest day is counted as paid leave
                $unpaidLeaveDays = $empLeave['unpaid'];
                $unpaidRestDays = max(0, $restDays - $paidRestDays);

                // ── Salary Calculation (per documentation) ──
                // Salary comes from the employee's payroll record only. With no
                // payroll assigned there is no salary to list, not a figure
                // borrowed from the wage master.
                $basicSalary = \App\Models\EmployeePayroll::monthlyPay($activePayroll);
                $shiftAllowance = 0;
                $incentives = 0;

                // The monthly entitlement. A day of absence is priced against
                // this and deliberately not against overtime: overtime pays for
                // hours already worked, so charging absence to it would deduct
                // the same day twice.
                $monthlyEarnings = $basicSalary + $shiftAllowance + $incentives;
                $perDaySalary = $daysInMonth > 0 ? $monthlyEarnings / $daysInMonth : 0;

                // Gross = Basic + Shift Allowance + Incentives + Overtime
                $overtimeHours = round($overtimeHoursMap[$employee->id] ?? 0, 2);
                $overtimeRate = isset($overtimeRates[$employee->skill_category]) && $overtimeRates[$employee->skill_category]
                    ? (float) $overtimeRates[$employee->skill_category]->overtime_rate
                    : 0.0;
                $overtimePayment = round($overtimeHours * $overtimeRate, 2);

                $grossSalary = $monthlyEarnings + $overtimePayment;

                // Recovery deduction, capped at a share of gross and carried
                // forward. This is a read-only listing, so plan the month
                // without writing installment rows.
                $recoveryPlan = $recoveryService->planEmployeeRecoveries(
                    $employee->id,
                    $grossSalary,
                    $month,
                    $year
                );
                $penaltyTotal = $recoveryPlan['total'];

                // Unmarked days count as absent: effective_absent = total - accounted days
                $effectiveAbsent = max(0, $daysInMonth - $presentDays - $halfDays - $paidLeaveDays - $holidays);
                // Leave Deduction: Absent/Unmarked=No Pay, Half Day=Half Pay
                $leaveDeduction = round($perDaySalary * ($effectiveAbsent + ($halfDays * 0.5)), 2);
                $payableDays = max(0.0, (float) ($daysInMonth - ($effectiveAbsent + ($halfDays * 0.5))));

                // Fixed deductions
                $pfApplicable = (bool) optional($activePayroll)->pf_applicable;
                $messDeductionApplicable = (bool) optional($activePayroll)->mess_deduction_applicable;
                $otherDeductionApplicable = (bool) optional($activePayroll)->other_deduction_appliacble;

                $pfDeduction = $pfApplicable ? (float) optional($activePayroll)->pf_amount : 0;
                $messDeduction = $messDeductionApplicable ? (float) optional($activePayroll)->mess_deduction_amount : 0;
                $otherDeduction = $otherDeductionApplicable ? (float) optional($activePayroll)->other_deduction : 0;

                // Net = Gross − (PF + Mess + Leave Deduction + Penalty + Other Deduction)
                $totalDeductions = $pfDeduction + $messDeduction + $leaveDeduction + $penaltyTotal + $otherDeduction;
                $netSalary = max(0, round($grossSalary - $totalDeductions, 2));

                $payroll = $existingPayrolls->get($employee->id);

                return [
                    'id' => $employee->id,
                    'payroll_id' => $payroll ? $payroll->id : null,
                    'payroll_status' => $payroll ? $payroll->status : null,
                    'employee_code' => $employee->employee_code,
                    'name' => $employee->name,
                    'department' => optional($employee->department)->name,
                    'designation' => optional($employee->designation)->name,
                    'site' => optional($employee->site)->site_name,
                    'site_id' => $employee->site_id,
                    'department_id' => $employee->department_id,
                    'month' => $month,
                    'year' => $year,
                    'days_in_month' => $daysInMonth,
                    'present_days' => $presentDays,
                    'absent_days' => $absentDays,
                    'half_days' => $halfDays,
                    'rest_days' => "{$restDays}/{$restDaysSetting}",
                    'holidays' => $holidays,
                    'paid_leave_days' => $empLeave['paid'],
                    'unpaid_leave_days' => $unpaidLeaveDays,
                    'payable_days' => round((float) $payableDays, 2),
                    'penalty_amount' => $this->money($penaltyTotal),
                    'recovery_limit' => $this->money($recoveryPlan['budget']),
                    'recovery_carried_forward' => $this->money($recoveryPlan['carried']),
                    'basic_salary' => $this->money($basicSalary),
                    'shift_allowance' => $this->money($shiftAllowance),
                    'incentives' => $this->money($incentives),
                    'overtime_hours' => round((float) $overtimeHours, 2),
                    'overtime_payment' => $this->money($overtimePayment),
                    'gross_salary' => $this->money($grossSalary),
                    'leave_deduction' => $this->money($leaveDeduction),
                    'pf_deduction' => $this->money($pfDeduction),
                    'mess_deduction' => $this->money($messDeduction),
                    'other_deduction' => $this->money($otherDeduction),
                    'monthly_salary' => $this->money($grossSalary),
                    'net_salary' => $this->money($netSalary),
                    'created_at' => ($payroll && $payroll->created_at) ? $payroll->created_at->toDateTimeString() : $employee->created_at->toDateTimeString(),
                ];
            });

            $employees->setCollection($result);

            return response()->json([
                'status' => 200,
                'message' => 'Payroll data fetched successfully',
                'data' => $employees->items(),
                'pagination' => [
                    'current_page' => $employees->currentPage(),
                    'last_page' => $employees->lastPage(),
                    'per_page' => $employees->perPage(),
                    'total' => $employees->total(),
                    'from' => $employees->firstItem(),
                    'to' => $employees->lastItem(),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile(),
            ], 500);
        }
    }

    /**
     * Generate / re-generate payroll for a specific month & year.
     *
     * Can target a single employee or all active employees.
     */
    public function generate(Request $request)
    {
        try {
            $request->validate([
                'month' => 'required|integer|between:1,12',
                'year' => 'required|integer|min:2020',
                'employee_id' => 'nullable|exists:employees,id',
                'employee_ids' => 'nullable|array',
                'employee_ids.*' => 'exists:employees,id',
            ]);

            $month = (int) $request->month;
            $year = (int) $request->year;

            $monthStart = Carbon::create($year, $month, 1)->startOfDay();
            $monthEnd = $monthStart->copy()->endOfMonth();

            // Determine target employees
            if ($request->filled('employee_id')) {
                $employees = Employee::with(['activePayroll'])->where('id', $request->employee_id)->get();
            } elseif ($request->filled('employee_ids')) {
                $employees = Employee::with(['activePayroll'])->whereIn('id', $request->employee_ids)->get();
            } else {
                $employees = Employee::with(['activePayroll'])->where('is_active', true)->get();
            }

            // Filter employees based on joining date
            $employees = $employees->filter(function ($emp) use ($monthEnd) {
                return Carbon::parse($emp->joining_date)->lte($monthEnd);
            });

            if ($employees->isEmpty()) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No employees found',
                ], 404);
            }

            $daysInMonth = Carbon::create($year, $month)->daysInMonth;

            // Overtime hours and rates, shared with the wage register so both
            // modules count the same hours.
            $overtimeHoursMap = app(\App\Services\WageRegisterService::class)
                ->overtimeSummary($employees->pluck('id')->all(), $month, $year);
            $overtimeRates = \App\Models\EmployeeWage::effectiveSet($monthEnd->toDateString());

            $generated = 0;

            // Distinct leave dates per employee, net of days attendance already
            // pays for. Same rule as the listing, so a generated payroll cannot
            // disagree with the screen it was generated from.
            $leaveSummary = \App\Services\LeaveBalanceService::monthlyLeaveDays(
                $employees->pluck('id')->all(),
                $month,
                $year
            );

            DB::transaction(function () use ($employees, $month, $year, $daysInMonth, $monthStart, $monthEnd, &$generated, $overtimeHoursMap, $overtimeRates, $leaveSummary) {
                foreach ($employees as $employee) {

                    // ── Attendance summary ──
                    $attendance = AttendanceProcessed::where('employee_id', $employee->id)
                        ->whereMonth('date', $month)->whereYear('date', $year)
                        ->selectRaw('
                            SUM(CASE WHEN attendance_status = "present" THEN 1 ELSE 0 END) as present_days,
                            SUM(CASE WHEN attendance_status = "absent" THEN 1 ELSE 0 END) as absent_days,
                            SUM(CASE WHEN attendance_status = "half_day" THEN 1 ELSE 0 END) as half_days,
                            SUM(CASE WHEN attendance_status = "leave" THEN 1 ELSE 0 END) as leave_days,
                            SUM(CASE WHEN attendance_status = "rest_day" THEN 1 ELSE 0 END) as rest_days
                        ')->first();

                    $presentDays = $attendance ? (int) $attendance->present_days : 0;
                    $absentDays = $attendance ? (int) $attendance->absent_days : 0;
                    $halfDays = $attendance ? (int) $attendance->half_days : 0;
                    $leaveDays = $attendance ? (int) $attendance->leave_days : 0;
                    $restDays = $attendance ? (int) $attendance->rest_days : 0;

                    // ── Leave breakdown (paid vs unpaid) ──
                    $empLeave = $leaveSummary[$employee->id] ?? ['paid' => 0, 'unpaid' => 0];
                    $paidLeaveDays = $empLeave['paid'];
                    $unpaidLeaveDays = $empLeave['unpaid'];

                    // ── Holidays for this employee's site ──
                    $holidays = Holiday::whereMonth('holiday_date', $month)->whereYear('holiday_date', $year)
                        ->where('is_active', true)
                        ->where(function ($q) use ($employee) {
                            $q->whereNull('site_id')->orWhere('site_id', $employee->site_id);
                        })->count();

                    // ── Earnings ──
                    $activePayroll = $employee->activePayroll;
                    // Salary comes from the employee's payroll record only.
                    $basicSalary = \App\Models\EmployeePayroll::monthlyPay($activePayroll);
                    $shiftAllowance = 0;
                    $incentives = 0;
                    // Absence is priced against the monthly entitlement only —
                    // overtime pays for hours already worked, so charging
                    // absence to it would deduct the same day twice.
                    $monthlyEarnings = $basicSalary + $shiftAllowance + $incentives;
                    $perDaySalary = $daysInMonth > 0 ? $monthlyEarnings / $daysInMonth : 0;

                    $overtimeHours = round($overtimeHoursMap[$employee->id] ?? 0, 2);
                    $overtimeRate = isset($overtimeRates[$employee->skill_category]) && $overtimeRates[$employee->skill_category]
                        ? (float) $overtimeRates[$employee->skill_category]->overtime_rate
                        : 0.0;
                    $overtimePayment = round($overtimeHours * $overtimeRate, 2);

                    $grossSalary = $monthlyEarnings + $overtimePayment;

                    // rest day is counted as paid leave
                    $restDaysSetting = \App\Services\LeaveBalanceService::monthlyPaidRestDays();
                    $paidRestDays = min($restDays, $restDaysSetting);
                    $paidLeaveDays += $paidRestDays;

                    // Unmarked days count as absent: effective_absent = total - accounted days
                    $effectiveAbsent = max(0, $daysInMonth - $presentDays - $halfDays - $paidLeaveDays - $holidays);
                    // Leave Deduction: Absent/Unmarked=No Pay, Half Day=Half Pay
                    $leaveDeduction = round($perDaySalary * ($effectiveAbsent + ($halfDays * 0.5)), 2);

                    // ── Fixed Deductions ──
                    $pfApplicable = (bool) optional($activePayroll)->pf_applicable;
                    $messDeductionApplicable = (bool) optional($activePayroll)->mess_deduction_applicable;
                    $otherDeductionApplicable = (bool) optional($activePayroll)->other_deduction_appliacble;

                    $pfDeduction = $pfApplicable ? (float) optional($activePayroll)->pf_amount : 0;
                    $messDeduction = $messDeductionApplicable ? (float) optional($activePayroll)->mess_deduction_amount : 0;
                    $otherDeduction = $otherDeductionApplicable ? (float) optional($activePayroll)->other_deduction : 0;

                    // ── Recovery (penalty / fine / damage / loss / advance / loan) ──
                    // Capped at a share of gross; the balance carries into
                    // later months as installments.
                    $recoveryPlan = app(\App\Services\LoanRecoveryService::class)
                        ->applyEmployeeRecoveries(
                            $employee->id,
                            $grossSalary,
                            $month,
                            $year
                        );
                    $penaltyTotal = $recoveryPlan['total'];

                    // ── Net = Gross − (PF + Mess + Leave Deduction + Penalty + Other Deduction) ──
                    $totalDeductions = $pfDeduction + $messDeduction + $leaveDeduction + $penaltyTotal + $otherDeduction;
                    $netSalary = max(0, round($grossSalary - $totalDeductions, 2));

                    // ── Upsert payroll record ──
                    $payroll = Payroll::updateOrCreate(
                        ['employee_id' => $employee->id, 'month' => $month, 'year' => $year],
                        [
                            'basic_salary' => $basicSalary,
                            'shift_allowance' => $shiftAllowance,
                            'incentives' => $incentives,
                            'overtime_hours' => $overtimeHours,
                            'overtime_payment' => $overtimePayment,
                            'present_days' => $presentDays,
                            'half_days' => $halfDays,
                            'absent_days' => $absentDays,
                            'leave_days' => $leaveDays,
                            'paid_leave_days' => $paidLeaveDays,
                            'unpaid_leave_days' => $unpaidLeaveDays,
                            'gross_salary' => $grossSalary,
                            'pf_deduction' => $pfDeduction,
                            'mess_deduction' => $messDeduction,
                            'other_deduction' => $otherDeduction,
                            'leave_deduction' => $leaveDeduction,
                            'penalty_deduction' => $penaltyTotal,
                            'net_salary' => $netSalary,
                            'status' => 'generated',
                            'generated_by' => auth()->id(),
                        ]
                    );

                    // Record which payroll the installments were taken on.
                    app(\App\Services\LoanRecoveryService::class)
                        ->attachToPayroll($employee->id, $month, $year, $payroll->id);

                    $generated++;
                }
            });

            return response()->json([
                'status' => 200,
                'message' => "{$generated} payroll record(s) generated successfully",
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile(),
            ], 500);
        }
    }

    /**
     * Show detailed payroll for a specific employee + month/year.
     */
    public function show(Request $request, $employeeId)
    {
        try {
            $request->validate([
                'month' => 'required|integer|between:1,12',
                'year' => 'required|integer|min:2020',
            ]);

            $month = (int) $request->month;
            $year = (int) $request->year;

            $monthStart = Carbon::create($year, $month, 1)->startOfDay();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $employee = Employee::with(['department', 'designation', 'site', 'activePayroll'])->find($employeeId);

            if (!$employee || Carbon::parse($employee->joining_date)->gt($monthEnd)) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Employee not found',
                ], 404);
            }

            $daysInMonth = Carbon::create($year, $month)->daysInMonth;
            $monthEnd = $monthStart->copy()->endOfMonth();

            // ── Attendance breakdown ──
            $attendance = AttendanceProcessed::where('employee_id', $employeeId)
                ->whereMonth('date', $month)
                ->whereYear('date', $year)
                ->selectRaw('
                    SUM(CASE WHEN attendance_status = "present" THEN 1 ELSE 0 END) as present_days,
                    SUM(CASE WHEN attendance_status = "absent" THEN 1 ELSE 0 END) as absent_days,
                    SUM(CASE WHEN attendance_status = "half_day" THEN 1 ELSE 0 END) as half_days,
                    SUM(CASE WHEN attendance_status = "leave" THEN 1 ELSE 0 END) as leave_days,
                    SUM(CASE WHEN attendance_status = "rest_day" THEN 1 ELSE 0 END) as rest_days
                ')
                ->first();

            // ── Leave breakdown ──
            // Distinct leave dates net of days attendance already pays for,
            // same as the listing and the generate path.
            $empLeave = \App\Services\LeaveBalanceService::monthlyLeaveDays(
                [$employeeId],
                $month,
                $year
            )[$employeeId] ?? ['paid' => 0, 'unpaid' => 0];

            $paidLeaveDays = $empLeave['paid'];
            $unpaidLeaveDays = $empLeave['unpaid'];

            // ── Holidays ──
            $holidays = Holiday::whereMonth('holiday_date', $month)
                ->whereYear('holiday_date', $year)
                ->where('is_active', true)
                ->where(function ($q) use ($employee) {
                    $q->whereNull('site_id')
                        ->orWhere('site_id', $employee->site_id);
                })
                ->count();

            // ── Salary calculation ──
            $presentDays = $attendance ? (int) $attendance->present_days : 0;
            $absentDays = $attendance ? (int) $attendance->absent_days : 0;
            $halfDays = $attendance ? (int) $attendance->half_days : 0;
            $restDays = $attendance ? (int) $attendance->rest_days : 0;

            // Earnings
            $activePayroll = $employee->activePayroll;

            $overtimeHoursMap = app(\App\Services\WageRegisterService::class)
                ->overtimeSummary([$employee->id], $month, $year);
            // The wage master supplies the overtime rate only; basic salary
            // comes from the employee's payroll record.
            $overtimeRates = \App\Models\EmployeeWage::effectiveSet(
                Carbon::create($year, $month, 1)->endOfMonth()->toDateString()
            );

            $basicSalary = \App\Models\EmployeePayroll::monthlyPay($activePayroll);
            $shiftAllowance = 0;
            $incentives = 0;
            // Absence is priced against the monthly entitlement only — overtime
            // pays for hours already worked, so charging absence to it would
            // deduct the same day twice.
            $monthlyEarnings = $basicSalary + $shiftAllowance + $incentives;
            $perDaySalary = $daysInMonth > 0 ? $monthlyEarnings / $daysInMonth : 0;

            $overtimeHours = round($overtimeHoursMap[$employee->id] ?? 0, 2);
            $overtimeRate = isset($overtimeRates[$employee->skill_category]) && $overtimeRates[$employee->skill_category]
                ? (float) $overtimeRates[$employee->skill_category]->overtime_rate
                : 0.0;
            $overtimePayment = round($overtimeHours * $overtimeRate, 2);

            $grossSalary = $monthlyEarnings + $overtimePayment;

            // ── Recoveries ──
            // Capped at a share of gross and carried forward, so this has to
            // run once gross is known. Read-only endpoint: plan, don't persist.
            $recoveryPlan = app(\App\Services\LoanRecoveryService::class)
                ->planEmployeeRecoveries($employeeId, $grossSalary, $month, $year);

            $penalties = $recoveryPlan['lines'];
            $penaltyTotal = $recoveryPlan['total'];

            // rest day is counted as paid leave
            $restDaysSetting = \App\Services\LeaveBalanceService::monthlyPaidRestDays();
            $paidRestDays = min($restDays, $restDaysSetting);
            $approvedPaidLeaves = $paidLeaveDays;
            $paidLeaveDays += $paidRestDays;

            // Unmarked days count as absent: effective_absent = total - accounted days
            $effectiveAbsent = max(0, $daysInMonth - $presentDays - $halfDays - $paidLeaveDays - $holidays);
            // Leave Deduction: Absent/Unmarked=No Pay, Half Day=Half Pay
            $leaveDeduction = round($perDaySalary * ($effectiveAbsent + ($halfDays * 0.5)), 2);
            $payableDays = max(0.0, (float) ($daysInMonth - ($effectiveAbsent + ($halfDays * 0.5))));

            // Fixed deductions
            $pfApplicable = (bool) optional($activePayroll)->pf_applicable;
            $messDeductionApplicable = (bool) optional($activePayroll)->mess_deduction_applicable;
            $otherDeductionApplicable = (bool) optional($activePayroll)->other_deduction_appliacble;

            $pfDeduction = $pfApplicable ? (float) optional($activePayroll)->pf_amount : 0;
            $messDeduction = $messDeductionApplicable ? (float) optional($activePayroll)->mess_deduction_amount : 0;
            $otherDeduction = $otherDeductionApplicable ? (float) optional($activePayroll)->other_deduction : 0;

            // Net = Gross − (PF + Mess + Leave Deduction + Penalty + Other Deduction)
            $totalDeductions = $pfDeduction + $messDeduction + $leaveDeduction + $penaltyTotal + $otherDeduction;
            $netSalary = max(0, round($grossSalary - $totalDeductions, 2));

            // ── Existing payroll record ──
            $existingPayroll = Payroll::where('employee_id', $employeeId)
                ->where('month', $month)
                ->where('year', $year)
                ->first();

            $status = $existingPayroll ? $existingPayroll->status : 'generated';

            $payroll = Payroll::updateOrCreate(
                ['employee_id' => $employeeId, 'month' => $month, 'year' => $year],
                [
                    'basic_salary' => $basicSalary,
                    'shift_allowance' => $shiftAllowance,
                    'incentives' => $incentives,
                    'overtime_hours' => $overtimeHours,
                    'overtime_payment' => $overtimePayment,
                    'present_days' => $presentDays,
                    'half_days' => $halfDays,
                    'absent_days' => $absentDays,
                    'leave_days' => $paidLeaveDays + $unpaidLeaveDays,
                    'paid_leave_days' => $paidLeaveDays,
                    'unpaid_leave_days' => $unpaidLeaveDays,
                    'gross_salary' => $grossSalary,
                    'pf_deduction' => $pfDeduction,
                    'mess_deduction' => $messDeduction,
                    'other_deduction' => $otherDeduction,
                    'leave_deduction' => $leaveDeduction,
                    'penalty_deduction' => $penaltyTotal,
                    'net_salary' => $netSalary,
                    'status' => $status,
                    'generated_by' => auth()->id(),
                ]
            );

            return response()->json([
                'status' => 200,
                'message' => 'Payroll details fetched successfully',
                'data' => [
                    'employee' => [
                        'id' => $employee->id,
                        'employee_code' => $employee->employee_code,
                        'name' => $employee->full_name,
                        'department' => optional($employee->department)->name,
                        'designation' => optional($employee->designation)->name,
                        'site' => optional($employee->site)->site_name,
                        'salary_type' => optional($activePayroll)->salary_type,
                        'bank_name' => optional($activePayroll)->bank_name,
                        'bank_account_number' => optional($activePayroll)->bank_account_number,
                        'ifsc_code' => optional($activePayroll)->ifsc_code,
                    ],
                    'payroll_period' => [
                        'month' => $month,
                        'year' => $year,
                        'month_name' => Carbon::create($year, $month)->format('F Y'),
                        'days_in_month' => $daysInMonth,
                    ],
                    'attendance' => [
                        'present_days' => $presentDays,
                        'absent_days' => $absentDays,
                        'half_days' => $halfDays,
                        'rest_days' => $restDays,
                        'holidays' => $holidays,
                        'paid_leave_days' => $approvedPaidLeaves,
                        'unpaid_leave_days' => $unpaidLeaveDays,
                        'payable_days' => round((float) $payableDays, 2),
                    ],
                    'earnings' => [
                        'basic_salary' => $this->money($basicSalary),
                        'shift_allowance' => $this->money($shiftAllowance),
                        'incentives' => $this->money($incentives),
                        'overtime_hours' => round((float) $overtimeHours, 2),
                        'overtime_payment' => $this->money($overtimePayment),
                        'gross_salary' => $this->money($grossSalary),
                        'per_day_salary' => $this->money($perDaySalary),
                    ],
                    'deductions' => [
                        'pf_deduction' => $this->money($pfDeduction),
                        'mess_deduction' => $this->money($messDeduction),
                        'leave_deduction' => $this->money($leaveDeduction),
                        'other_deduction' => $this->money($otherDeduction),
                        'penalty_amount' => $this->money($penaltyTotal),
                        'total_deductions' => $this->money($totalDeductions),
                    ],
                    'penalties' => $penalties,
                    'net_salary' => $this->money($netSalary),
                    'payroll_record' => $payroll ? [
                        'id' => $payroll->id,
                        'status' => $payroll->status,
                        'net_salary' => $this->money($payroll->net_salary),
                        'created_at' => $payroll->created_at->toDateTimeString(),
                    ] : null,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile(),
            ], 500);
        }
    }

    /**
     * Update payroll status (draft → generated → paid).
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|in:draft,generated,paid',
            ]);

            $payroll = Payroll::find($id);

            if (!$payroll) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Payroll record not found',
                ], 404);
            }

            $payroll->update(['status' => $request->status]);

            return response()->json([
                'status' => 200,
                'message' => 'Payroll status updated to ' . $request->status,
                'data' => new PayrollResource($payroll->load('employee')),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk update payroll status (e.g. mark multiple as "paid").
     */
    public function bulkUpdateStatus(Request $request)
    {
        try {
            $request->validate([
                'payroll_ids' => 'required|array|min:1',
                'payroll_ids.*' => 'exists:payrolls,id',
                'status' => 'required|in:draft,generated,paid',
            ]);

            Payroll::whereIn('id', $request->payroll_ids)
                ->update(['status' => $request->status]);

            return response()->json([
                'status' => 200,
                'message' => count($request->payroll_ids) . ' payroll record(s) updated to ' . $request->status,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a payroll record.
     */
    public function destroy($id)
    {
        $payroll = Payroll::find($id);

        if (!$payroll) {
            return response()->json([
                'status' => 404,
                'message' => 'Payroll record not found',
            ], 404);
        }

        $payroll->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Payroll record deleted successfully',
        ]);
    }

    /**
     * Get penalty details for a specific employee for a payroll row.
     */
    public function employeePenalties(Request $request, $employeeId)
    {
        try {
            $request->validate([
                'month' => 'required|integer|between:1,12',
                'year' => 'required|integer|min:2020',
            ]);

            $month = (int) $request->month;
            $year = (int) $request->year;

            $monthStart = Carbon::create($year, $month, 1)->startOfDay();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $employee = Employee::find($employeeId);

            if (!$employee || Carbon::parse($employee->joining_date)->gt($monthEnd)) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Employee not found',
                ], 404);
            }

            /*
             * This breakdown has to agree with the payroll row it opens
             * from, so it applies the same cap. Where payroll has already
             * been generated its stored gross is authoritative — that is
             * the figure the deduction was actually calculated against.
             */
            $payroll = Payroll::where('employee_id', $employeeId)
                ->where('month', $month)
                ->where('year', $year)
                ->first();

            if ($payroll) {
                $grossSalary = (float) $payroll->gross_salary;
            } else {
                $overtimeHours = round(
                    app(\App\Services\WageRegisterService::class)
                        ->overtimeSummary([$employee->id], $month, $year)[$employee->id] ?? 0,
                    2
                );
                $overtimeRates = \App\Models\EmployeeWage::effectiveSet($monthEnd->toDateString());
                $overtimeRate = isset($overtimeRates[$employee->skill_category]) && $overtimeRates[$employee->skill_category]
                    ? (float) $overtimeRates[$employee->skill_category]->overtime_rate
                    : 0.0;

                $grossSalary = \App\Models\EmployeePayroll::monthlyPay($employee->activePayroll)
                    + round($overtimeHours * $overtimeRate, 2);
            }

            $recoveryPlan = app(\App\Services\LoanRecoveryService::class)
                ->planEmployeeRecoveries($employeeId, $grossSalary, $month, $year);

            $monthName = Carbon::create($year, $month)->format('M Y');

            $formattedPenalties = collect($recoveryPlan['lines'])->map(function ($line) {
                return [
                    'id' => $line['penalty_id'],
                    'date' => $line['date'],
                    'recovery_type' => $line['recovery_type'],
                    'particulars' => $line['particulars'],
                    'reason' => $line['reason'],
                    'total_amount' => $line['total_amount'],
                    'opening_balance' => $line['opening_balance'],
                    // What comes out of this month's salary.
                    'amount' => $line['installment_amount'],
                    'closing_balance' => $line['closing_balance'],
                    'fully_recovered' => $line['fully_recovered'],
                ];
            });

            return response()->json([
                'status' => 200,
                'message' => 'Penalty details fetched successfully',
                'data' => [
                    'employee' => [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'employee_code' => $employee->employee_code,
                    ],
                    'total_penalty' => (float) $recoveryPlan['total'],
                    'recovery_limit' => $this->money($recoveryPlan['budget']),
                    'recovery_carried_forward' => $this->money($recoveryPlan['carried']),
                    'month_name' => $monthName,
                    'penalties' => $formattedPenalties,
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
                'line' => $th->getLine(),
                'file' => $th->getFile(),
            ], 500);
        }
    }
}
