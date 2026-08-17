<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRegisterReport;
use App\Services\LeaveBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Form E leave balances. Read-only — every figure is computed from the leave
 * master, approved leaves and attendance. See App\Services\LeaveBalanceService.
 */
class LeaveBalanceController extends Controller
{
    protected $balances;

    public function __construct(LeaveBalanceService $balances)
    {
        $this->balances = $balances;
    }

    /**
     * The register itself: one row per employee for the year.
     */
    public function index(Request $request)
    {
        try {
            $year = (int) $request->input('year', now()->year);

            if ($year < 2000 || $year > 2100) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Invalid year.'
                ], 422);
            }

            $query = $this->balances->registerEmployeeQuery($year, $request->only([
                'site_id', 'department_id', 'designation_id', 'search', 'employee_status',
            ]));

            $limit = (int) $request->input('limit', 25);
            $paginator = $limit > 0 ? $query->paginate($limit) : null;
            $employees = collect($paginator ? $paginator->items() : $query->get());

            $ledger = $this->balances->ledgerFor($employees, $year);

            // Column 1 continues across pages so the printed register runs 1..n.
            $serial = $paginator
                ? (($paginator->currentPage() - 1) * $paginator->perPage()) + 1
                : 1;

            // Same flat Form E shape a generated report returns, so one table
            // component renders both the preview and the saved register.
            $rows = [];
            foreach ($employees as $employee) {
                $entry = $ledger[$employee->id] ?? ['days_worked' => 0.0, 'groups' => []];

                $rows[] = \App\Models\LeaveRegisterReportRow::formEFromLedger($serial++, $employee, $entry);
            }

            $response = [
                'status' => 200,
                'message' => 'Leave balances fetched successfully',
                'year' => $year,
                'data' => $rows,
            ];

            if ($paginator) {
                $response['pagination'] = [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ];
            }

            return response()->json($response);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * One employee's ledger for a year.
     */
    public function employee(Request $request, int $employeeId)
    {
        try {
            $year = (int) $request->input('year', now()->year);

            $employee = Employee::find($employeeId);

            if (! $employee) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Employee not found'
                ], 404);
            }

            $entry = $this->balances->ledgerForEmployee($employee, $year);

            return response()->json([
                'status' => 200,
                'message' => 'Leave balance fetched successfully',
                'data' => [
                    'employee' => [
                        'id' => $employee->id,
                        'employee_code' => $employee->employee_code,
                        'name' => trim($employee->name . ' ' . $employee->surname),
                    ],
                    'year' => $year,
                    'days_worked' => $entry['days_worked'],
                    'balances' => array_values($entry['groups']),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * The Generate button: freeze this year's register and store it.
     */
    public function generate(Request $request)
    {
        // Validated outside the try: ValidationException is a Throwable, so the
        // catch below would turn a 422 with field errors into a bare 500.
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'year' => 'nullable|integer|between:2000,2100',
            'remarks' => 'nullable|string|max:1000',
            'replace' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $year = (int) $request->input('year', now()->year);

            // One report per year. If this year already has one, stop and let
            // the caller confirm rather than silently discarding it; they
            // re-send with replace=true to go ahead.
            $existing = LeaveRegisterReport::forYear($year);

            if ($existing && ! $request->boolean('replace')) {
                $generatedOn = optional($existing->generated_at)->format('d M Y, h:i A');

                return response()->json([
                    'status' => 409,
                    'requires_confirmation' => true,
                    'message' => "The leave register for {$year} was already generated"
                        . ($generatedOn ? " on {$generatedOn}" : '')
                        . '. Do you want to replace it?',
                    'data' => $this->reportHeader($existing->load('generatedBy.employee')),
                ], 409);
            }

            $report = $this->balances->generateReport(
                $year,
                Auth::id(),
                $request->input('remarks')
            );

            if ($report->employee_count === 0) {
                return response()->json([
                    'status' => 422,
                    'message' => "No employees were on the register in {$year}, so there is nothing to generate."
                ], 422);
            }

            return response()->json([
                'status' => 200,
                'message' => $existing
                    ? "Leave register for {$year} replaced"
                    : "Leave register generated for {$year}",
                'data' => $this->reportHeader($report->load('generatedBy.employee')),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * With ?year= this returns that year's register itself, rows and all —
     * there is only ever one report per year, so the year identifies it. Without
     * a year it lists the generated reports so the UI can offer them as filters.
     */
    public function reports(Request $request)
    {
        if ($request->filled('year')) {
            $report = LeaveRegisterReport::forYear((int) $request->year);

            if (! $report) {
                return response()->json([
                    'status' => 404,
                    'message' => "No leave register has been generated for {$request->year} yet."
                ], 404);
            }

            return $this->reportShow($request, $report->id);
        }

        try {
            // One report per year, so year alone is a total ordering.
            $query = LeaveRegisterReport::with('generatedBy.employee')->orderByDesc('year');

            if ($request->filled('from_year')) {
                $query->where('year', '>=', (int) $request->from_year);
            }

            if ($request->filled('to_year')) {
                $query->where('year', '<=', (int) $request->to_year);
            }

            $limit = (int) $request->input('limit', 25);
            $paginator = $query->paginate($limit > 0 ? $limit : 25);

            return response()->json([
                'status' => 200,
                'message' => 'Generated leave registers fetched successfully',
                'data' => collect($paginator->items())->map(function ($report) {
                    return $this->reportHeader($report);
                })->all(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * A stored report, exactly as it was generated. Nothing here is recomputed.
     */
    public function reportShow(Request $request, int $id)
    {
        try {
            $report = LeaveRegisterReport::with('generatedBy.employee')->find($id);

            if (! $report) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Generated report not found'
                ], 404);
            }

            $limit = (int) $request->input('limit', 25);
            $rowQuery = $report->rows();

            if ($request->filled('search')) {
                $search = $request->search;
                $rowQuery->where(function ($q) use ($search) {
                    $q->where('employee_name', 'LIKE', "%{$search}%")
                        ->orWhere('employee_code', 'LIKE', "%{$search}%");
                });
            }

            $paginator = $limit > 0 ? $rowQuery->paginate($limit) : null;
            $rows = collect($paginator ? $paginator->items() : $rowQuery->get());

            $response = [
                'status' => 200,
                'message' => 'Generated leave register fetched successfully',
                'data' => $this->reportHeader($report) + [
                    'rows' => $rows->map(function ($row) {
                        return $row->toFormE();
                    })->all(),
                ],
            ];

            if ($paginator) {
                $response['pagination'] = [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ];
            }

            return response()->json($response);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    public function reportDestroy(int $id)
    {
        $report = LeaveRegisterReport::find($id);

        if (! $report) {
            return response()->json([
                'status' => 404,
                'message' => 'Generated report not found'
            ], 404);
        }

        $report->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Generated report deleted successfully'
        ]);
    }

    /**
     * The `users` table has no name column — a person's name lives on their
     * employee record, reached through role_user. Falls back to the login
     * email so this never comes back null.
     */
    protected function userDisplayName($user): ?string
    {
        $employee = $user->employee;

        if ($employee) {
            $name = trim($employee->name . ' ' . $employee->surname);

            if ($name !== '') {
                return $name;
            }
        }

        return $user->email;
    }

    protected function reportHeader(LeaveRegisterReport $report): array
    {
        return [
            'id' => $report->id,
            'year' => $report->year,
            'version' => $report->version,
            'employee_count' => $report->employee_count,
            'generated_at' => optional($report->generated_at)->toDateTimeString(),
            'generated_by' => $report->generatedBy ? [
                'id' => $report->generatedBy->id,
                'name' => $this->userDisplayName($report->generatedBy),
                'email' => $report->generatedBy->email,
            ] : null,
            // What the quotas were when this was frozen.
            'leave_type_snapshot' => $report->leave_type_snapshot,
            'remarks' => $report->remarks,
        ];
    }
}
