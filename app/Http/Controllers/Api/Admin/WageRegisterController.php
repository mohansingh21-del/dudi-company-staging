<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exports\WageRegisterExport;
use App\Http\Controllers\Controller;
use App\Models\WageRegisterReport;
use App\Models\WageRegisterUpload;
use App\Models\WageRegisterUploadRow;
use App\Services\WageRegisterImportService;
use App\Services\WageRegisterService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Form B wage registers — the month list, the frozen register itself, and a
 * live preview of a month before it is generated.
 *
 * Known gap: nothing in the schema records overtime hours, so column 5 always
 * generates as 0 and column 9 with it. Columns 7, 10, 11, 14-17, 19 and 22 have
 * no source either and are stored null so the printed form shows a blank rather
 * than a 0 the employer never actually paid. Columns 23-24 are filled in by
 * hand on the printout.
 */
class WageRegisterController extends Controller
{
    protected $register;

    public function __construct(WageRegisterService $register)
    {
        $this->register = $register;
    }

    /**
     * The month list for a year — every month of it, generated or not, so the
     * listing can show "NOT GENERATED" against the ones still outstanding.
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

            $reports = WageRegisterReport::with('generatedBy.employee', 'generatedBy.roles')
                ->where('year', $year)
                ->get()
                ->keyBy('month');

            // Months that have not happened yet are not listed as outstanding.
            $lastMonth = ($year < now()->year) ? 12 : (int) now()->month;

            $months = [];

            for ($month = 1; $month <= $lastMonth; $month++) {
                $report = $reports->get($month);

                $months[] = [
                    'month' => $month,
                    'year' => $year,
                    'month_label' => Carbon::create($year, $month, 1)->format('F Y'),
                    'status' => $report ? 'generated' : 'not_generated',
                    'report_id' => $report ? $report->id : null,
                    'employee_count' => $report ? $report->employee_count : null,
                    'total_earnings' => $report ? $report->total_earnings : null,
                    'total_deductions' => $report ? $report->total_deductions : null,
                    'total_net' => $report ? $report->total_net : null,
                    'version' => $report ? $report->version : null,
                    'generated_at' => $report ? optional($report->generated_at)->toDateTimeString() : null,
                ];
            }

            $generated = collect($months)->where('status', 'generated');

            return response()->json([
                'status' => 200,
                'message' => 'Wage register months fetched successfully',
                'data' => array_reverse($months),
                'summary' => [
                    'year' => $year,
                    'generated_months' => $generated->count(),
                    'pending_months' => count($months) - $generated->count(),
                    'total_earnings' => round($generated->sum('total_earnings'), 2),
                    'total_deductions' => round($generated->sum('total_deductions'), 2),
                    'total_net' => round($generated->sum('total_net'), 2),
                ],
                'available_years' => WageRegisterReport::distinct()
                    ->orderByDesc('year')
                    ->pluck('year'),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * The "Continue / Check Salary" step: pick a month and either be warned that
     * it is already filed, or be handed the sheet to work on.
     *
     * Answers in two shapes, which the caller has to tell apart:
     *
     *   already generated — JSON describing the existing register, whether it
     *                       can be replaced, and how complete the data is
     *   not generated yet — the .xlsx working sheet, downloaded directly
     *
     * Both carry an `X-Wage-Register-Status` header (`generated` /
     * `not_generated`), which is the reliable thing to branch on. It is exposed
     * through CORS so a browser client can actually read it.
     *
     * Reads only; nothing is created either way.
     */
    public function check(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|between:2000,2100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $month = (int) $request->month;
            $year = (int) $request->year;
            $label = Carbon::create($year, $month, 1)->format('F Y');

            $existing = WageRegisterReport::with('generatedBy.employee', 'generatedBy.roles')->where('month', $month)
                ->where('year', $year)
                ->first();

            // Nothing filed for this month yet, so there is nothing to warn
            // about — hand back the working sheet instead of a report saying it
            // does not exist. The caller gets a spreadsheet download here and
            // JSON in every other case, so it has to branch on the response:
            // either the content type, or the X-Wage-Register-Status header set
            // below, which is present on both paths.
            if (!$existing) {
                $download = $this->export($request);

                // export() answers with JSON when it cannot produce a file —
                // no employees on the register, for instance — and that reply
                // should reach the caller untouched.
                if ($download instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
                    || $download instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
                    $download->headers->set('X-Wage-Register-Status', 'not_generated');
                    $download->headers->set('Access-Control-Expose-Headers', 'X-Wage-Register-Status, Content-Disposition');
                }

                return $download;
            }

            // A month still running would freeze an incomplete register, so it
            // is reported as blocked rather than merely "not generated".
            $monthIsOver = ! Carbon::create($year, $month, 1)->endOfMonth()->isFuture();

            // Only reached when a register already exists, so the caller is
            // being told about that rather than handed a sheet.
            return response()->json([
                'status' => 200,
                'message' => "A wage register for {$label} already exists.",
                'data' => [
                    'month' => $month,
                    'year' => $year,
                    'month_label' => $label,

                    'exists' => true,
                    'status' => 'generated',

                    // Generating over an existing register replaces it, so the
                    // caller should confirm first — same rule generate() applies.
                    'requires_confirmation' => true,
                    'can_generate' => $monthIsOver,
                    'blocked_reason' => $monthIsOver
                        ? null
                        : "{$label} has not finished yet.",

                    'report' => $this->reportHeader($existing),
                    'readiness' => $this->readinessFor($month, $year),
                ],
            ])->header('X-Wage-Register-Status', 'generated')
              ->header('Access-Control-Expose-Headers', 'X-Wage-Register-Status, Content-Disposition');
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * How complete the source data is for a month.
     *
     * Attendance drives every earned figure, so a month with almost none of it
     * generates a register saying nobody was paid. This surfaces that before the
     * register is frozen rather than after.
     */
    protected function readinessFor(int $month, int $year): array
    {
        $employees = $this->register->registerEmployeeQuery($month, $year)->get();
        $ids = $employees->pluck('id')->all();
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;

        $attendanceDays = 0;
        $employeesWithAttendance = 0;

        if (!empty($ids)) {
            $perEmployee = \App\Models\AttendanceProcessed::whereIn('employee_id', $ids)
                ->whereMonth('date', $month)->whereYear('date', $year)
                ->groupBy('employee_id')
                ->selectRaw('employee_id, COUNT(*) as days')
                ->pluck('days', 'employee_id');

            $attendanceDays = (int) $perEmployee->sum();
            $employeesWithAttendance = $perEmployee->count();
        }

        $expectedDays = count($ids) * $daysInMonth;
        $coverage = $expectedDays > 0 ? round(($attendanceDays / $expectedDays) * 100, 1) : 0.0;

        // Unmarked days count as absent, so an employee with no attendance at
        // all still prints a row — one that earns nothing but is charged every
        // fixed deduction. Worth naming as a warning, not just a statistic.
        $withoutPayroll = $employees->filter(fn($e) => !$e->activePayroll)->count();
        $withoutSkill = $employees->whereNull('skill_category')->count();

        $warnings = [];

        if (count($ids) === 0) {
            $warnings[] = 'No employees are on the register for this month.';
        }

        if ($employeesWithAttendance < count($ids)) {
            $missing = count($ids) - $employeesWithAttendance;
            $warnings[] = "{$missing} employee(s) have no attendance recorded for this month and will earn nothing.";
        }

        if ($withoutPayroll > 0) {
            $warnings[] = "{$withoutPayroll} employee(s) have no active payroll record, so they have no salary to price against.";
        }

        if ($withoutSkill > 0) {
            $warnings[] = "{$withoutSkill} employee(s) have no skill category, so dearness allowance cannot be split out for them.";
        }

        return [
            'eligible_employees' => count($ids),
            'employees_with_attendance' => $employeesWithAttendance,
            'employees_without_payroll' => $withoutPayroll,
            'employees_without_skill_category' => $withoutSkill,
            'attendance_days_recorded' => $attendanceDays,
            'expected_attendance_days' => $expectedDays,
            'attendance_coverage_percent' => $coverage,
            'warnings' => $warnings,
        ];
    }

    /**
     * A month as it would be generated right now, without saving anything —
     * lets the register be checked before it is frozen.
     */
    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|between:2000,2100',
            'search' => 'nullable|string|max:255',
            'site_id' => 'nullable|exists:sites,id',
            'department_id' => 'nullable|exists:departments,id',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $month = (int) $request->month;
            $year = (int) $request->year;

            $query = $this->register->registerEmployeeQuery($month, $year, $request->only([
                'site_id', 'department_id', 'search',
            ]));

            $limit = (int) $request->input('limit', 25);
            $paginator = $query->paginate($limit);
            $employees = collect($paginator->items());

            $lines = $this->register->linesFor($employees, $month, $year);

            // Column 1 continues across pages so the printed register runs 1..n.
            $serial = (($paginator->currentPage() - 1) * $paginator->perPage()) + 1;

            // Same flat Form B shape a generated report returns, so one table
            // component renders both the preview and the saved register.
            $rows = [];

            foreach ($employees as $employee) {
                if (!isset($lines[$employee->id])) {
                    continue;
                }

                $row = new \App\Models\WageRegisterReportRow($lines[$employee->id]);
                $row->serial_no = $serial++;
                $row->employee_id = $employee->id;

                $rows[] = $row->toFormB();
            }

            $existing = WageRegisterReport::forMonth($month, $year);

            return response()->json([
                'status' => 200,
                'message' => 'Wage register preview generated successfully',
                'data' => [
                    'month' => $month,
                    'year' => $year,
                    'month_label' => Carbon::create($year, $month, 1)->format('F Y'),
                    'status' => $existing ? 'generated' : 'not_generated',
                    'report_id' => $existing ? $existing->id : null,
                    'wage_rates' => $this->register->rateSnapshot($year, $month),
                    'rows' => $rows,
                ],
                'pagination' => $this->paginationOf($paginator),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Freeze a month. A month that already has a register is not overwritten
     * silently — the caller re-sends with replace=true to confirm.
     */
    public function generate(Request $request)
    {
        // Validated outside the try: ValidationException is a Throwable, so the
        // catch below would turn a 422 with field errors into a bare 500.
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|between:2000,2100',
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
            $month = (int) $request->month;
            $year = (int) $request->year;
            $label = Carbon::create($year, $month, 1)->format('F Y');

            // A month still running would freeze an incomplete register.
            if (Carbon::create($year, $month, 1)->endOfMonth()->isFuture()) {
                return response()->json([
                    'status' => 422,
                    'message' => "{$label} has not finished yet, so its wage register cannot be generated."
                ], 422);
            }

            $existing = WageRegisterReport::forMonth($month, $year);

            if ($existing && ! $request->boolean('replace')) {
                $generatedOn = optional($existing->generated_at)->format('d M Y, h:i A');

                return response()->json([
                    'status' => 409,
                    'requires_confirmation' => true,
                    'message' => "The wage register for {$label} was already generated"
                        . ($generatedOn ? " on {$generatedOn}" : '')
                        . '. Do you want to replace it?',
                    'data' => $this->reportHeader($existing->load('generatedBy.employee', 'generatedBy.roles')),
                ], 409);
            }

            $report = $this->register->generateReport($month, $year, Auth::id(), $request->input('remarks'));

            if ($report->employee_count === 0) {
                $report->delete();

                return response()->json([
                    'status' => 422,
                    'message' => "No employees were on the register in {$label}, so there is nothing to generate."
                ], 422);
            }

            return response()->json([
                'status' => 200,
                'message' => $existing
                    ? "Wage register for {$label} replaced"
                    : "Wage register generated for {$label}",
                'data' => $this->reportHeader($report->load('generatedBy.employee', 'generatedBy.roles')),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * A frozen register: header, the stat tiles, and the Form B rows. Rows are
     * read back as stored and never recomputed.
     */
    public function show(Request $request, $id)
    {
        try {
            $report = WageRegisterReport::with('generatedBy.employee', 'generatedBy.roles')->find($id);

            if (!$report) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Wage register not found'
                ], 404);
            }

            $rowQuery = $report->rows();

            if ($request->filled('search')) {
                $search = $request->search;

                $rowQuery->where(function ($q) use ($search) {
                    $q->where('employee_name', 'LIKE', "%{$search}%")
                        ->orWhere('employee_code', 'LIKE', "%{$search}%");
                });
            }

            $limit = (int) $request->input('limit', 25);
            $paginator = $rowQuery->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Wage register fetched successfully',
                'data' => array_merge($this->reportHeader($report), [
                    'rows' => collect($paginator->items())->map->toFormB()->values(),
                ]),
                'pagination' => $this->paginationOf($paginator),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * The live calculated figures for a month as an .xlsx in the Form B layout.
     *
     * This is the working copy, not a record: nothing is saved, and the sheet is
     * meant to be corrected by hand and imported back before the register is
     * generated. Every eligible employee is included — there is no pagination,
     * because a partial sheet would import as a partial register.
     */
    public function export(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|between:2000,2100',
            'site_id' => 'nullable|exists:sites,id',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $month = (int) $request->month;
            $year = (int) $request->year;
            $label = Carbon::create($year, $month, 1)->format('F Y');

            $employees = $this->register
                ->registerEmployeeQuery($month, $year, $request->only(['site_id', 'department_id']))
                ->get();

            if ($employees->isEmpty()) {
                return response()->json([
                    'status' => 422,
                    'message' => "No employees are on the register for {$label}, so there is nothing to export."
                ], 422);
            }

            $lines = $this->register->linesFor($employees, $month, $year);

            // Built through the row model so the sheet and the API agree on the
            // Form B shape rather than each assembling it their own way.
            $rows = [];
            $serial = 1;

            foreach ($employees as $employee) {
                if (!isset($lines[$employee->id])) {
                    continue;
                }

                $row = new \App\Models\WageRegisterReportRow($lines[$employee->id]);
                $row->serial_no = $serial++;
                $row->employee_id = $employee->id;
                $row->employee_code = $employee->employee_code;

                $rows[] = $row->toFormB();
            }

            $filename = 'wage-register-' . Carbon::create($year, $month, 1)->format('Y-m') . '.xlsx';

            return Excel::download(new WageRegisterExport($rows, $label), $filename);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Take back an edited Form B sheet.
     *
     * The figures are staged and validated only. Nothing here updates
     * employee_payrolls, payrolls, attendance or the wage master — a changed
     * amount is the user's correction to their own register, not to the records
     * the register was calculated from.
     *
     * Rows that fail validation are stored too, with the failing cells named, so
     * the preview can mark them and the user can fix and resend.
     */
    public function import(Request $request, WageRegisterImportService $importer)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|between:2000,2100',
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $month = (int) $request->month;
            $year = (int) $request->year;
            $label = Carbon::create($year, $month, 1)->format('F Y');

            $upload = $importer->import(
                $request->file('file'),
                $month,
                $year,
                Auth::id(),
                $request->file('file')->getClientOriginalName()
            );

            if ($upload->total_rows === 0) {
                $upload->delete();

                return response()->json([
                    'status' => 422,
                    'message' => 'No employee rows were found in the file. Check that it is the exported wage register sheet.'
                ], 422);
            }

            return response()->json([
                'status' => 200,
                'message' => $upload->error_rows > 0
                    ? "{$upload->error_rows} row(s) need correcting before this register can be generated."
                    : "{$upload->valid_rows} row(s) uploaded successfully for {$label}.",
                'data' => $this->uploadPayload($upload->load('uploadedBy.employee', 'uploadedBy.roles')),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * The uploaded documents list — one line per staged sheet, which is what the
     * user picks "Preview" from. Carries no row data.
     */
    public function importIndex(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'nullable|integer|between:1,12',
            'year' => 'nullable|integer|between:2000,2100',
            'status' => 'nullable|in:pending,ready',
            'search' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = WageRegisterUpload::with('uploadedBy.employee', 'uploadedBy.roles');

            if ($request->filled('month')) {
                $query->where('month', (int) $request->month);
            }

            if ($request->filled('year')) {
                $query->where('year', (int) $request->year);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $query->where('original_filename', 'LIKE', "%{$request->search}%");
            }

            $uploads = $query->latest('id')->paginate((int) $request->input('limit', 25));

            $rows = collect($uploads->items())->map(fn($upload) => [
                'upload_id' => $upload->id,
                'document_id' => $upload->document_id,
                'file_name' => $upload->original_filename,
                'uploaded_by' => $this->userSummary($upload->uploadedBy),
                'uploaded_at' => optional($upload->created_at)->toDateTimeString(),

                'month' => $upload->month,
                'year' => $upload->year,
                'month_label' => $upload->month_label,

                'status' => $upload->status,
                'status_label' => $upload->status_label,
                'has_errors' => $upload->error_rows > 0,

                'total_rows' => $upload->total_rows,
                'valid_rows' => $upload->valid_rows,
                'error_rows' => $upload->error_rows,
            ])->values();

            return response()->json([
                'status' => 200,
                'message' => 'Uploaded documents fetched successfully',
                'data' => $rows,
                'pagination' => $this->paginationOf($uploads),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * The rows behind one uploaded document — the Preview screen.
     *
     * Fetched separately from the list so a sheet's worth of rows is only loaded
     * when the user actually opens it. Pass "latest" as the id to get the most
     * recent upload without knowing its id.
     */
    public function stagedImport(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'only' => 'nullable|in:all,valid,error',
            'search' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $upload = $id === 'latest'
                ? WageRegisterUpload::with('uploadedBy.employee', 'uploadedBy.roles')->latest('id')->first()
                : WageRegisterUpload::with('uploadedBy.employee', 'uploadedBy.roles')->find($id);

            if (!$upload) {
                return response()->json([
                    'status' => 404,
                    'message' => $id === 'latest'
                        ? 'No sheet has been uploaded yet.'
                        : 'Uploaded document not found.'
                ], 404);
            }

            $rowQuery = $upload->rows();

            // Lets the preview jump straight to the rows needing correction.
            if ($request->input('only') === 'error') {
                $rowQuery->where('is_valid', false);
            } elseif ($request->input('only') === 'valid') {
                $rowQuery->where('is_valid', true);
            }

            if ($request->filled('search')) {
                $search = $request->search;

                $rowQuery->where(function ($q) use ($search) {
                    $q->where('employee_name', 'LIKE', "%{$search}%")
                        ->orWhere('employee_code', 'LIKE', "%{$search}%");
                });
            }

            $paginator = $rowQuery->paginate((int) $request->input('limit', 25));

            return response()->json([
                'status' => 200,
                'message' => 'Uploaded rows fetched successfully',
                'data' => array_merge($this->uploadPayload($upload), [
                    'rows' => collect($paginator->items())->map->toPreview()->values(),
                ]),
                'pagination' => $this->paginationOf($paginator),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Correct cells on a staged row from the preview screen.
     *
     * Send one field or several: `{"values": {"special_basic": 5000}}`. Each is
     * parsed and validated exactly as the upload would have, so a value typed in
     * here cannot get past a check the spreadsheet was held to.
     *
     * The correction lands in the staging row only — nothing is written back to
     * employee_payrolls, payrolls or the wage master.
     */
    public function updateImportRow(Request $request, $uploadId, $excelRow, WageRegisterImportService $importer)
    {
        $fields = array_column(WageRegisterUploadRow::COLUMNS, 0);

        $validator = Validator::make($request->all(), [
            'values' => 'required|array|min:1',
            'values.*' => 'nullable',
        ]);

        $validator->after(function ($validator) use ($request, $fields) {
            foreach (array_keys((array) $request->input('values', [])) as $field) {
                if (!in_array($field, $fields, true)) {
                    $validator->errors()->add('values', "\"{$field}\" is not a column on this register.");
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Addressed the way the preview screen sees it: the document, and
            // the spreadsheet row number printed against the line.
            $upload = $uploadId === 'latest'
                ? WageRegisterUpload::latest('id')->first()
                : WageRegisterUpload::find($uploadId);

            if (!$upload) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Uploaded document not found.'
                ], 404);
            }

            $row = $upload->rows()->where('excel_row', (int) $excelRow)->first();

            if (!$row) {
                return response()->json([
                    'status' => 404,
                    'message' => "Row {$excelRow} was not found in this document."
                ], 404);
            }

            $result = $importer->updateRow($row, (array) $request->input('values'));

            // Nothing was written — the value did not pass, and the cell still
            // holds whatever it held before.
            if (!$result['saved']) {
                return response()->json([
                    'status' => 422,
                    'message' => 'The value entered is not valid, so nothing was changed.',
                    'errors' => $result['errors'],
                    'error_columns' => WageRegisterUploadRow::columnNumbersFor(array_keys($result['errors'])),
                ], 422);
            }

            $row = $row->fresh();
            $upload = $upload->fresh();

            // Just the outcome: what was saved, whether the row is now clean, and
            // the batch counters the screen shows. The table itself is not resent.
            return response()->json([
                'status' => 200,
                'message' => "Row {$row->excel_row} updated successfully.",
                'data' => [
                    'excel_row' => $row->excel_row,
                    'updated' => $result['applied'],
                    'row_is_valid' => $row->is_valid,
                    'remaining_errors' => array_keys($row->errors ?: []),
                    'valid_rows' => $upload->valid_rows,
                    'error_rows' => $upload->error_rows,
                    'status' => $upload->status,
                    'can_submit' => $upload->status === 'ready',
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
     * Remove an employee's row from a staged sheet before it is submitted.
     *
     * Staging only — the employee is untouched, and so is every table the row's
     * figures came from. Deleting after submission is refused: the register is
     * filed by then, and the two would disagree.
     */
    public function deleteImportRow($uploadId, $excelRow, WageRegisterImportService $importer)
    {
        try {
            $upload = $uploadId === 'latest'
                ? WageRegisterUpload::latest('id')->first()
                : WageRegisterUpload::find($uploadId);

            if (!$upload) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Uploaded document not found.'
                ], 404);
            }

            $row = $upload->rows()->where('excel_row', (int) $excelRow)->first();

            if (!$row) {
                return response()->json([
                    'status' => 404,
                    'message' => "Row {$excelRow} was not found in this document."
                ], 404);
            }

            $result = $importer->deleteRow($row);
            $upload = $upload->fresh();

            $name = $result['deleted']['employee_name'] ?: $result['deleted']['employee_code'];

            return response()->json([
                'status' => 200,
                'message' => "Row {$excelRow}" . ($name ? " ({$name})" : '') . ' removed.',
                'data' => [
                    'excel_row' => $result['deleted']['excel_row'],
                    'employee_code' => $result['deleted']['employee_code'],
                    'employee_name' => $result['deleted']['employee_name'],

                    // Rows elsewhere on the sheet whose errors changed as a
                    // result — the screen should refresh these.
                    'revalidated_rows' => $result['revalidated'],

                    'total_rows' => $upload->total_rows,
                    'valid_rows' => $upload->valid_rows,
                    'error_rows' => $upload->error_rows,
                    'status' => $upload->status,
                    'can_submit' => $upload->status === 'ready' && $upload->total_rows > 0,
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
     * Submit Data — move a staged sheet into the register proper.
     *
     * The frozen rows are copied from staging as reviewed, not recalculated: the
     * whole point of the correction step is that what was approved on screen is
     * what gets filed.
     *
     * Refuses while any row still has an error, and asks for confirmation before
     * replacing a register that month already has.
     */
    public function submitImport(Request $request, $uploadId, WageRegisterImportService $importer)
    {
        $validator = Validator::make($request->all(), [
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
            $upload = $uploadId === 'latest'
                ? WageRegisterUpload::latest('id')->first()
                : WageRegisterUpload::find($uploadId);

            if (!$upload) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Uploaded document not found.'
                ], 404);
            }

            $label = $upload->month_label;

            if ($upload->total_rows === 0) {
                return response()->json([
                    'status' => 422,
                    'message' => 'This sheet has no rows to submit.'
                ], 422);
            }

            // A register is a statutory record; filing one with cells known to be
            // wrong is worse than not filing it yet.
            if ($upload->error_rows > 0) {
                return response()->json([
                    'status' => 422,
                    'message' => "{$upload->error_rows} row(s) still have errors. Correct them before submitting.",
                    'data' => [
                        'error_rows' => $upload->error_rows,
                        'columns_with_errors' => $this->uploadPayload($upload)['columns_with_errors'],
                    ],
                ], 422);
            }

            $existing = WageRegisterReport::forMonth($upload->month, $upload->year);

            if ($existing && ! $request->boolean('replace')) {
                $generatedOn = optional($existing->generated_at)->format('d M Y, h:i A');

                return response()->json([
                    'status' => 409,
                    'requires_confirmation' => true,
                    'message' => "A wage register for {$label} already exists"
                        . ($generatedOn ? ", filed on {$generatedOn}" : '')
                        . '. Submitting this sheet will replace it. Continue?',
                    'data' => $this->reportHeader($existing->load('generatedBy.employee', 'generatedBy.roles')),
                ], 409);
            }

            $rowCount = $upload->total_rows;
            $report = $importer->commit($upload, Auth::id(), $request->input('remarks'));

            return response()->json([
                'status' => 200,
                'message' => $existing
                    ? "Wage register for {$label} replaced from the uploaded sheet."
                    : "Wage register for {$label} created from the uploaded sheet.",
                'data' => array_merge(
                    $this->reportHeader($report->load('generatedBy.employee', 'generatedBy.roles')),
                    [
                        // The staged sheet is gone — the register is the record
                        // now, so the preview screen should close rather than
                        // try to reload it.
                        'staging_cleared' => true,
                        'staged_rows_removed' => $rowCount,
                    ]
                ),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Throw away a staged sheet.
     */
    public function discardImport($id)
    {
        try {
            $upload = WageRegisterUpload::find($id);

            if (!$upload) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Staged upload not found'
                ], 404);
            }

            $label = $upload->month_label;
            $upload->delete();

            return response()->json([
                'status' => 200,
                'message' => "Staged upload for {$label} discarded"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * The batch summary and the failing cells gathered per column.
     *
     * Deliberately carries no rows: uploading only reports what happened, and
     * the table is fetched separately when the user opens it. The per-column
     * counts are read straight from the database so this stays cheap on a sheet
     * with thousands of lines.
     */
    protected function uploadPayload(WageRegisterUpload $upload): array
    {
        // Which Form B columns have a problem anywhere in the sheet, why, and
        // which spreadsheet rows to look at.
        $columnErrors = [];

        $failing = $upload->rows()
            ->where('is_valid', false)
            ->get(['excel_row', 'errors']);

        foreach ($failing as $row) {
            foreach (($row->errors ?: []) as $field => $message) {
                $columnErrors[$field]['rows'][] = $row->excel_row;

                // Grouped by the reason rather than the literal message. Two
                // rows reading "abc" and "pending" are the same mistake twice,
                // not two different problems, and summarising them as two would
                // overstate what is actually wrong with the sheet.
                $columnErrors[$field]['reasons'][$this->errorReason($message)] = true;
            }
        }

        $columns = [];

        foreach ($columnErrors as $field => $detail) {
            $label = WageRegisterUploadRow::COLUMN_LABELS[$field] ?? $field;
            $number = WageRegisterUploadRow::columnNumbersFor([$field])[0] ?? null;
            $reasons = array_keys($detail['reasons']);
            $rows = $detail['rows'];
            $count = count($rows);

            // Name the rows when there are few enough to be worth listing.
            $where = $count <= 5
                ? 'row ' . implode(', ', $rows)
                : "{$count} rows";

            $columns[] = [
                'field' => $field,
                'column' => $number,
                'label' => $label,
                'error_rows' => $count,

                // Which spreadsheet lines to jump to.
                'excel_rows' => $rows,

                // One sentence a user can act on, naming the column as it is
                // printed on the form and where to look.
                'message' => "Column {$number} ({$label}): "
                    . implode(' ', $reasons)
                    . " Affects {$where}.",

                // Each distinct reason, for a column that failed more than one way.
                'reasons' => $reasons,
            ];
        }

        return [
            'upload_id' => $upload->id,
            'month' => $upload->month,
            'year' => $upload->year,
            'month_label' => $upload->month_label,
            'original_filename' => $upload->original_filename,
            'uploaded_by' => $this->userSummary($upload->uploadedBy),
            'uploaded_at' => optional($upload->created_at)->toDateTimeString(),

            'document_id' => $upload->document_id,
            'status' => $upload->status,
            'status_label' => $upload->status_label,
            'can_submit' => $upload->status === 'ready',

            'summary' => [
                'total_rows' => $upload->total_rows,
                'valid_rows' => $upload->valid_rows,
                'error_rows' => $upload->error_rows,
                'errors_found' => $upload->error_rows > 0,
            ],

            // Everything the UI needs to paint a column red without scanning.
            'columns_with_errors' => $columns,
        ];
    }

    public function destroy($id)
    {
        try {
            $report = WageRegisterReport::find($id);

            if (!$report) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Wage register not found'
                ], 404);
            }

            $label = $report->month_label;

            // Rows go with it — the foreign key cascades.
            $report->delete();

            return response()->json([
                'status' => 200,
                'message' => "Wage register for {$label} deleted successfully"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * The header and stat tiles, shared by generate() and show() so both
     * describe a report the same way.
     */
    protected function reportHeader(WageRegisterReport $report): array
    {
        return [
            'id' => $report->id,
            'month' => $report->month,
            'year' => $report->year,
            'month_label' => $report->month_label,
            'status' => 'generated',
            'version' => $report->version,
            'generated_at' => optional($report->generated_at)->toDateTimeString(),
            'generated_by' => $this->userSummary($report->generatedBy),
            'employee_count' => $report->employee_count,
            'total_earnings' => $report->total_earnings,
            'total_deductions' => $report->total_deductions,
            'total_net' => $report->total_net,

            // Net is floored at 0 per row, so on a month where someone's
            // deductions outran their earnings the tiles will not satisfy
            // net = earnings - deductions. Summing the per-row floors gives
            // total_net - (total_earnings - total_deductions), which is the
            // written-off excess the UI needs to explain the gap.
            'unrecovered_deduction' => round(
                max(0, $report->total_net - $report->total_earnings + $report->total_deductions),
                2
            ),
            'wage_rates' => $report->wage_rate_snapshot,
            'remarks' => $report->remarks,
        ];
    }

    /**
     * A row-level message reduced to the reason behind it, so the column summary
     * can group rows that failed the same way and describe them in one sentence.
     *
     * Row messages quote the offending value ("abc" is not a valid amount…),
     * which makes each one unique and unusable as a summary. These are the
     * general forms, written to be read by whoever has to fix the sheet.
     */
    protected function errorReason(string $message): string
    {
        $reasons = [
            'is not a valid amount' => 'Contains a value that is not a number. Remove any letters or symbols.',
            'cannot be negative' => 'Contains a negative value.',
            'is not a valid Date of Payment' => 'Contains a date that could not be read. Use the format YYYY-MM-DD.',
            'No employee found with code' => 'Contains an employee code that does not exist in the system.',
            'Employee code is required' => 'Is blank. Every row needs an employee code.',
            'Duplicate row' => 'Lists the same employee more than once.',
            'Name does not match' => 'Has a name that does not match the employee code on that row.',
        ];

        foreach ($reasons as $needle => $reason) {
            if (str_contains($message, $needle)) {
                return $reason;
            }
        }

        // Anything unrecognised is passed through rather than dropped.
        return $message;
    }

    /**
     * Who a user is, for display.
     *
     * The users table holds no name — it is on the employee record reached
     * through role_user. Most accounts here are administrative and have no
     * employee at all, so the role is the next best label, and the email is the
     * last resort rather than the first.
     */
    protected function userDisplayName($user): ?string
    {
        if (!$user) {
            return null;
        }

        $employee = $user->employee;

        if ($employee) {
            $name = trim($employee->name . ' ' . $employee->surname);

            if ($name !== '') {
                return $name;
            }
        }

        $role = $user->roles->first();

        if ($role && trim((string) $role->name) !== '') {
            return $role->name;
        }

        return $user->email;
    }

    /**
     * The id alongside the name, so the caller is not left matching on a label.
     */
    protected function userSummary($user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $this->userDisplayName($user),
            'email' => $user->email,
        ];
    }

    protected function paginationOf($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
