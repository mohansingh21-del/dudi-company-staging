<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\Employee;
use App\Models\AttendanceProcessed;
use App\Models\Leave;
use App\Models\Penalty;
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

            // Approved leaves per employee for the month (paid vs unpaid)
            $leaves = Leave::whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->where(function ($q) use ($month, $year) {
                    $q->where(function ($q2) use ($month, $year) {
                        $q2->whereMonth('from_date', $month)->whereYear('from_date', $year);
                    })->orWhere(function ($q2) use ($month, $year) {
                        $q2->whereMonth('to_date', $month)->whereYear('to_date', $year);
                    });
                })
                ->with('leaveType')
                ->get();

            // Calculate leave days per employee (paid / unpaid)
            $leaveSummary = [];
            $monthStart = Carbon::create($year, $month, 1)->startOfDay();
            $monthEnd = $monthStart->copy()->endOfMonth();

            foreach ($leaves as $leave) {
                $empId = $leave->employee_id;
                if (!isset($leaveSummary[$empId])) {
                    $leaveSummary[$empId] = ['paid' => 0, 'unpaid' => 0];
                }

                $from = Carbon::parse($leave->from_date)->max($monthStart);
                $to = Carbon::parse($leave->to_date)->min($monthEnd);
                $days = $from->diffInDays($to) + 1;

                $category = optional($leave->leaveType)->leave_category ?? 'unpaid';
                if ($category === 'paid') {
                    $leaveSummary[$empId]['paid'] += $days;
                } else {
                    $leaveSummary[$empId]['unpaid'] += $days;
                }
            }

            // Penalty totals per employee
            $penaltyTotals = Penalty::whereIn('employee_id', $employeeIds)
                ->where('month', $month)
                ->where('year', $year)
                ->selectRaw('employee_id, SUM(amount) as total_penalty')
                ->groupBy('employee_id')
                ->pluck('total_penalty', 'employee_id');

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

            // ── Build result collection ──
            $result = $employees->getCollection()->map(function ($employee) use ($attendanceCounts, $leaveSummary, $penaltyTotals, $generalHolidays, $siteHolidays, $daysInMonth, $existingPayrolls, $month, $year) {
                $att = $attendanceCounts->get($employee->id);
                $empLeave = $leaveSummary[$employee->id] ?? ['paid' => 0, 'unpaid' => 0];
                $penaltyTotal = $penaltyTotals[$employee->id] ?? 0;

                $holidays = $generalHolidays + ($siteHolidays[$employee->site_id] ?? 0);
                $activePayroll = $employee->activePayroll;
                $restDaysSetting = $activePayroll ? (int) $activePayroll->rest_days : (int) $employee->rest_days;

                $presentDays = $att ? (int) $att->present_days : 0;
                $absentDays = $att ? (int) $att->absent_days : 0;
                $halfDays = $att ? (int) $att->half_days : 0;
                $restDays = $att ? (int) $att->rest_days : 0;
                $paidRestDays = min($restDays, $restDaysSetting);
                $paidLeaveDays = $empLeave['paid'] + $paidRestDays; // rest day is counted as paid leave
                $unpaidLeaveDays = $empLeave['unpaid'];
                $unpaidRestDays = max(0, $restDays - $paidRestDays);

                // ── Salary Calculation (per documentation) ──
                $basicSalary = $activePayroll ? (float) $activePayroll->basic_salary : (float) $employee->basic_salary;
                $shiftAllowance = 0;
                $incentives = 0;

                // Gross = Basic + Shift Allowance + Incentives
                $grossSalary = $basicSalary + $shiftAllowance + $incentives;
                $perDaySalary = $daysInMonth > 0 ? $grossSalary / $daysInMonth : 0;

                // Unmarked days count as absent: effective_absent = total - accounted days
                $effectiveAbsent = max(0, $daysInMonth - $presentDays - $halfDays - $paidLeaveDays - $holidays);
                // Leave Deduction: Absent/Unmarked=No Pay, Half Day=Half Pay
                $leaveDeduction = round($perDaySalary * ($effectiveAbsent + ($halfDays * 0.5)), 0);
                $payableDays = max(0.0, (float) ($daysInMonth - ($effectiveAbsent + ($halfDays * 0.5))));

                // Fixed deductions
                $pfApplicable = $activePayroll ? $activePayroll->pf_applicable : $employee->pf_applicable;
                $messDeductionApplicable = $activePayroll ? $activePayroll->mess_deduction_applicable : $employee->mess_deduction_applicable;

                $pfDeduction = $pfApplicable ? ($activePayroll && $activePayroll->pf_amount !== null ? (float) $activePayroll->pf_amount : (float) $employee->pf_amount) : 0;
                $messDeduction = $messDeductionApplicable ? ($activePayroll && $activePayroll->mess_deduction_amount !== null ? (float) $activePayroll->mess_deduction_amount : (float) $employee->mess_deduction_amount) : 0;

                $otherDeductionApplicable = $activePayroll ? $activePayroll->other_deduction_appliacble : $employee->other_deduction_appliacble;
                $otherDeduction = $otherDeductionApplicable ? ($activePayroll && $activePayroll->other_deduction !== null ? (float) $activePayroll->other_deduction : (float) $employee->other_deduction) : 0;

                // Net = Gross − (PF + Mess + Leave Deduction + Penalty + Other Deduction)
                $totalDeductions = $pfDeduction + $messDeduction + $leaveDeduction + $penaltyTotal + $otherDeduction;
                $netSalary = max(0, round($grossSalary - $totalDeductions, 0));

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
                    'payable_days' => $payableDays,
                    'penalty_amount' => (float) $penaltyTotal,
                    'basic_salary' => $basicSalary,
                    'shift_allowance' => $shiftAllowance,
                    'incentives' => $incentives,
                    'gross_salary' => $grossSalary,
                    'leave_deduction' => $leaveDeduction,
                    'pf_deduction' => $pfDeduction,
                    'mess_deduction' => $messDeduction,
                    'other_deduction' => $otherDeduction,
                    'monthly_salary' => $grossSalary,
                    'net_salary' => $netSalary,
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

            $generated = 0;

            DB::transaction(function () use ($employees, $month, $year, $daysInMonth, $monthStart, $monthEnd, &$generated) {
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
                    $paidLeaveDays = 0;
                    $unpaidLeaveDays = 0;
                    $approvedLeaves = Leave::where('employee_id', $employee->id)
                        ->where('status', 'approved')
                        ->where(function ($q) use ($month, $year) {
                            $q->where(function ($q2) use ($month, $year) {
                                $q2->whereMonth('from_date', $month)->whereYear('from_date', $year);
                            })->orWhere(function ($q2) use ($month, $year) {
                                $q2->whereMonth('to_date', $month)->whereYear('to_date', $year);
                            });
                        })->with('leaveType')->get();

                    foreach ($approvedLeaves as $leave) {
                        $category = optional($leave->leaveType)->leave_category ?? 'unpaid';
                        $from = Carbon::parse($leave->from_date)->max($monthStart);
                        $to = Carbon::parse($leave->to_date)->min($monthEnd);
                        $days = $from->diffInDays($to) + 1;
                        if ($category === 'paid') {
                            $paidLeaveDays += $days;
                        } else {
                            $unpaidLeaveDays += $days;
                        }
                    }

                    // ── Holidays for this employee's site ──
                    $holidays = Holiday::whereMonth('holiday_date', $month)->whereYear('holiday_date', $year)
                        ->where('is_active', true)
                        ->where(function ($q) use ($employee) {
                            $q->whereNull('site_id')->orWhere('site_id', $employee->site_id);
                        })->count();

                    // ── Earnings ──
                    $activePayroll = $employee->activePayroll;
                    $basicSalary = $activePayroll ? (float) $activePayroll->basic_salary : (float) $employee->basic_salary;
                    $shiftAllowance = 0;
                    $incentives = 0;
                    $grossSalary = $basicSalary + $shiftAllowance + $incentives;
                    $perDaySalary = $daysInMonth > 0 ? $grossSalary / $daysInMonth : 0;

                    // rest day is counted as paid leave
                    $restDaysSetting = $activePayroll ? (int) $activePayroll->rest_days : (int) $employee->rest_days;
                    $paidRestDays = min($restDays, $restDaysSetting);
                    $paidLeaveDays += $paidRestDays;

                    // Unmarked days count as absent: effective_absent = total - accounted days
                    $effectiveAbsent = max(0, $daysInMonth - $presentDays - $halfDays - $paidLeaveDays - $holidays);
                    // Leave Deduction: Absent/Unmarked=No Pay, Half Day=Half Pay
                    $leaveDeduction = round($perDaySalary * ($effectiveAbsent + ($halfDays * 0.5)), 0);

                    // ── Fixed Deductions ──
                    $pfApplicable = $activePayroll ? $activePayroll->pf_applicable : $employee->pf_applicable;
                    $messDeductionApplicable = $activePayroll ? $activePayroll->mess_deduction_applicable : $employee->mess_deduction_applicable;

                    $pfDeduction = $pfApplicable ? ($activePayroll && $activePayroll->pf_amount !== null ? (float) $activePayroll->pf_amount : (float) $employee->pf_amount) : 0;
                    $messDeduction = $messDeductionApplicable ? ($activePayroll && $activePayroll->mess_deduction_amount !== null ? (float) $activePayroll->mess_deduction_amount : (float) $employee->mess_deduction_amount) : 0;

                    $otherDeductionApplicable = $activePayroll ? $activePayroll->other_deduction_appliacble : $employee->other_deduction_appliacble;
                    $otherDeduction = $otherDeductionApplicable ? ($activePayroll && $activePayroll->other_deduction !== null ? (float) $activePayroll->other_deduction : (float) $employee->other_deduction) : 0;

                    // ── Penalty ──
                    $penaltyTotal = Penalty::where('employee_id', $employee->id)
                        ->where('month', $month)->where('year', $year)->sum('amount');

                    // ── Net = Gross − (PF + Mess + Leave Deduction + Penalty + Other Deduction) ──
                    $totalDeductions = $pfDeduction + $messDeduction + $leaveDeduction + $penaltyTotal + $otherDeduction;
                    $netSalary = max(0, round($grossSalary - $totalDeductions, 0));

                    // ── Upsert payroll record ──
                    Payroll::updateOrCreate(
                        ['employee_id' => $employee->id, 'month' => $month, 'year' => $year],
                        [
                            'basic_salary' => $basicSalary,
                            'shift_allowance' => $shiftAllowance,
                            'incentives' => $incentives,
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
            $approvedLeaves = Leave::where('employee_id', $employeeId)
                ->where('status', 'approved')
                ->where(function ($q) use ($month, $year) {
                    $q->where(function ($q2) use ($month, $year) {
                        $q2->whereMonth('from_date', $month)->whereYear('from_date', $year);
                    })->orWhere(function ($q2) use ($month, $year) {
                        $q2->whereMonth('to_date', $month)->whereYear('to_date', $year);
                    });
                })
                ->with('leaveType')
                ->get();

            $paidLeaveDays = 0;
            $unpaidLeaveDays = 0;
            foreach ($approvedLeaves as $leave) {
                $category = optional($leave->leaveType)->leave_category ?? 'unpaid';
                $from = Carbon::parse($leave->from_date)->max($monthStart);
                $to = Carbon::parse($leave->to_date)->min($monthEnd);
                $days = $from->diffInDays($to) + 1;
                if ($category === 'paid') {
                    $paidLeaveDays += $days;
                } else {
                    $unpaidLeaveDays += $days;
                }
            }

            // ── Penalties ──
            $penalties = Penalty::where('employee_id', $employeeId)
                ->where('month', $month)
                ->where('year', $year)
                ->get(['id', 'penalty_date', 'reason', 'amount']);

            $penaltyTotal = $penalties->sum('amount');

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
            $basicSalary = $activePayroll ? (float) $activePayroll->basic_salary : (float) $employee->basic_salary;
            $shiftAllowance = 0;
            $incentives = 0;
            $grossSalary = $basicSalary + $shiftAllowance + $incentives;
            $perDaySalary = $daysInMonth > 0 ? $grossSalary / $daysInMonth : 0;

            // rest day is counted as paid leave
            $restDaysSetting = $activePayroll ? (int) $activePayroll->rest_days : (int) $employee->rest_days;
            $paidRestDays = min($restDays, $restDaysSetting);
            $approvedPaidLeaves = $paidLeaveDays;
            $paidLeaveDays += $paidRestDays;

            // Unmarked days count as absent: effective_absent = total - accounted days
            $effectiveAbsent = max(0, $daysInMonth - $presentDays - $halfDays - $paidLeaveDays - $holidays);
            // Leave Deduction: Absent/Unmarked=No Pay, Half Day=Half Pay
            $leaveDeduction = round($perDaySalary * ($effectiveAbsent + ($halfDays * 0.5)), 0);
            $payableDays = max(0.0, (float) ($daysInMonth - ($effectiveAbsent + ($halfDays * 0.5))));

            // Fixed deductions
            $pfApplicable = $activePayroll ? $activePayroll->pf_applicable : $employee->pf_applicable;
            $messDeductionApplicable = $activePayroll ? $activePayroll->mess_deduction_applicable : $employee->mess_deduction_applicable;

            $pfDeduction = $pfApplicable ? ($activePayroll && $activePayroll->pf_amount !== null ? (float) $activePayroll->pf_amount : (float) $employee->pf_amount) : 0;
            $messDeduction = $messDeductionApplicable ? ($activePayroll && $activePayroll->mess_deduction_amount !== null ? (float) $activePayroll->mess_deduction_amount : (float) $employee->mess_deduction_amount) : 0;

            $otherDeductionApplicable = $activePayroll ? $activePayroll->other_deduction_appliacble : $employee->other_deduction_appliacble;
            $otherDeduction = $otherDeductionApplicable ? ($activePayroll && $activePayroll->other_deduction !== null ? (float) $activePayroll->other_deduction : (float) $employee->other_deduction) : 0;

            // Net = Gross − (PF + Mess + Leave Deduction + Penalty + Other Deduction)
            $totalDeductions = $pfDeduction + $messDeduction + $leaveDeduction + $penaltyTotal + $otherDeduction;
            $netSalary = max(0, round($grossSalary - $totalDeductions, 0));

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
                        'name' => $employee->name,
                        'department' => optional($employee->department)->name,
                        'designation' => optional($employee->designation)->name,
                        'site' => optional($employee->site)->site_name,
                        'salary_type' => $activePayroll ? $activePayroll->salary_type : $employee->salary_type,
                        'bank_name' => $activePayroll ? $activePayroll->bank_name : $employee->bank_name,
                        'bank_account_number' => $activePayroll ? $activePayroll->bank_account_number : $employee->bank_account_number,
                        'ifsc_code' => $activePayroll ? $activePayroll->ifsc_code : $employee->ifsc_code,
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
                        'payable_days' => $payableDays,
                    ],
                    'earnings' => [
                        'basic_salary' => $basicSalary,
                        'shift_allowance' => $shiftAllowance,
                        'incentives' => $incentives,
                        'gross_salary' => $grossSalary,
                        'per_day_salary' => (float) round($perDaySalary, 0),
                    ],
                    'deductions' => [
                        'pf_deduction' => $pfDeduction,
                        'mess_deduction' => $messDeduction,
                        'leave_deduction' => $leaveDeduction,
                        'other_deduction' => $otherDeduction,
                        'penalty_amount' => (float) $penaltyTotal,
                        'total_deductions' => $totalDeductions,
                    ],
                    'penalties' => $penalties,
                    'net_salary' => $netSalary,
                    'payroll_record' => $payroll ? [
                        'id' => $payroll->id,
                        'status' => $payroll->status,
                        'net_salary' => (float) $payroll->net_salary,
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

            $penalties = Penalty::where('employee_id', $employeeId)
                ->where('month', $month)
                ->where('year', $year)
                ->orderBy('penalty_date', 'asc')
                ->get();

            $totalPenalty = $penalties->sum('amount');
            $monthName = Carbon::create($year, $month)->format('M Y');

            $formattedPenalties = $penalties->map(function ($p) {
                return [
                    'id' => $p->id,
                    'date' => Carbon::parse($p->penalty_date)->format('d M Y'),
                    'reason' => $p->reason,
                    'amount' => (float) $p->amount,
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
                    'total_penalty' => (float) $totalPenalty,
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
