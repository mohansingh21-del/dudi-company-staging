<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\RecoveryType;
use App\Http\Controllers\Controller;
use Illuminate\Validation\Rule;
use App\Models\Penalty;
use App\Models\Employee;
use App\Http\Requests\StorePenaltyRequest;
use App\Http\Requests\StoreBulkPenaltyRequest;
use App\Http\Requests\UpdatePenaltyRequest;
use App\Http\Resources\PenaltyResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Exports\PenaltiesExport;
use Maatwebsite\Excel\Facades\Excel;

class PenaltyController extends Controller
{
    /**
     * Display a listing of penalties.
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);
            $query = Penalty::with('employee');

            // Filter by search (employee name, code, reason)
            if ($request->filled('search')) {
                $search = $request->search;

                $query->where(function ($q) use ($search) {

                    $q->where('reason', 'LIKE', "%{$search}%")
                        ->orWhere('particulars', 'LIKE', "%{$search}%")
                        ->orWhere('recovery_type', 'LIKE', "%{$search}%")
                        ->orWhere('remarks', 'LIKE', "%{$search}%")
                        ->orWhereHas('employee', function ($empQuery) use ($search) {
                            $empQuery
                                ->where('name', 'LIKE', "%{$search}%")
                                ->orWhere(
                                    'employee_code',
                                    'LIKE',
                                    "%{$search}%"
                                );
                        });
                });
            }
            if ($request->filled('recovery_type')) {
                $query->where(
                    'recovery_type',
                    $request->recovery_type
                );
            }


            // Filter by employee_id
            if ($request->filled('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }

            // Filter by specific month and year
            if ($request->filled('month')) {
                $query->where('month', $request->month);
            }
            if ($request->filled('year')) {
                $query->where('year', $request->year);
            }

            // Filter by penalty_date string
            if ($request->filled('penalty_date')) {
                try {
                    $parsed = Carbon::parse($request->penalty_date);
                    $query->whereDate('penalty_date', $parsed->toDateString());
                } catch (\Exception $e) {
                    // Ignore invalid format in filter
                }
            }

            $penalties = $query->latest()->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Penalties fetched successfully',
                'data' => PenaltyResource::collection($penalties),
                'pagination' => [
                    'current_page' => $penalties->currentPage(),
                    'last_page' => $penalties->lastPage(),
                    'per_page' => $penalties->perPage(),
                    'total' => $penalties->total(),
                    'from' => $penalties->firstItem(),
                    'to' => $penalties->lastItem(),
                ]
            ]);
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
     * Store a newly created penalty.
     */
    public function storeOLD(StorePenaltyRequest $request)
    {
        try {
            $parsedDate = Carbon::parse($request->penalty_date);

            $penalty = Penalty::create([
                'employee_id' => $request->employee_id,
                'penalty_date' => $parsedDate->toDateString(),
                'month' => $parsedDate->month,
                'year' => $parsedDate->year,
                'reason' => $request->reason,
                'amount' => $request->amount,
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Penalty applied successfully',
                'data' => new PenaltyResource($penalty->load('employee'))
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }
    public function store(StorePenaltyRequest $request)
    {
        try {
            $parsedDate = Carbon::parse($request->penalty_date);

            $employee = Employee::with('activePayroll')->find($request->employee_id);

            // Installment count and end period follow from the 25% cap.
            $plan = $this->scheduleFor(
                $employee,
                (float) $request->amount,
                $parsedDate,
                $request->first_month,
                $request->first_year
            );

            $penalty = Penalty::create([
                'employee_id' => $request->employee_id,

                // Current/register fields
                'penalty_date' => $parsedDate->toDateString(),
                'month' => $parsedDate->month,
                'year' => $parsedDate->year,

                'recovery_type' => $request->recovery_type,

                'reason' => $request->reason,
                'particulars' => $request->particulars,

                'amount' => $request->amount,

                'show_cause_issued' => $request->boolean('show_cause_issued'),

                'explanation_heard_in_presence' =>
                $request->explanation_heard_in_presence,

                'number_of_installments' =>
                $plan['number_of_installments'],

                'installment_amount' =>
                $plan['installment_amount'],

                'first_month' =>
                $plan['first_month'],

                'first_year' =>
                $plan['first_year'],

                'last_month' =>
                $plan['last_month'],

                'last_year' =>
                $plan['last_year'],

                'date_of_complete_recovery' =>
                $plan['date_of_complete_recovery'],

                'remarks' =>
                $request->remarks,

                /*
             * IMMUTABLE CALCULATION SNAPSHOT
             */
                'calculation_amount' => $request->amount,

                'calculation_recovery_type' =>
                $request->recovery_type,

                'calculation_particulars' =>
                $request->particulars,

                'calculation_date' =>
                $parsedDate->toDateString(),

                'calculation_number_of_installments' =>
                $plan['number_of_installments'],

                'calculation_first_month' =>
                $plan['first_month'],

                'calculation_first_year' =>
                $plan['first_year'],

                'calculation_last_month' =>
                $plan['last_month'],

                'calculation_last_year' =>
                $plan['last_year'],
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Recovery applied successfully',
                'data' => new PenaltyResource(
                    $penalty->load('employee')
                ),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Store multiple penalties in bulk.
     */
    public function storeBulkOLD(StoreBulkPenaltyRequest $request)
    {
        try {
            $parsedDate = Carbon::parse($request->penalty_date);
            $penaltiesData = [];

            DB::transaction(function () use ($request, $parsedDate, &$penaltiesData) {
                foreach ($request->employee_ids as $employeeId) {
                    $penalty = Penalty::create([
                        'employee_id' => $employeeId,
                        'penalty_date' => $parsedDate->toDateString(),
                        'month' => $parsedDate->month,
                        'year' => $parsedDate->year,
                        'reason' => $request->reason,
                        'amount' => $request->amount,
                    ]);
                    $penaltiesData[] = $penalty;
                }
            });

            return response()->json([
                'status' => 200,
                'message' => count($penaltiesData) . ' penalties applied successfully',
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }
    public function storeBulk(StoreBulkPenaltyRequest $request)
    {
        try {
            $parsedDate = Carbon::parse($request->penalty_date);

            $penaltiesData = [];

            DB::transaction(function () use (
                $request,
                $parsedDate,
                &$penaltiesData
            ) {
                foreach ($request->employee_ids as $employeeId) {

                    $penalty = Penalty::create([

                        'employee_id' => $employeeId,

                        // Current data
                        'penalty_date' => $parsedDate->toDateString(),
                        'month' => $parsedDate->month,
                        'year' => $parsedDate->year,

                        'recovery_type' => $request->recovery_type,
                        'reason' => $request->reason,
                        'particulars' => $request->particulars,
                        'amount' => $request->amount,

                        'show_cause_issued' =>
                        $request->boolean('show_cause_issued'),

                        'explanation_heard_in_presence' =>
                        $request->explanation_heard_in_presence,

                        'number_of_installments' =>
                        $request->number_of_installments,

                        'first_month' =>
                        $request->first_month,

                        'first_year' =>
                        $request->first_year,

                        'last_month' =>
                        $request->last_month,

                        'last_year' =>
                        $request->last_year,

                        'date_of_complete_recovery' =>
                        $request->date_of_complete_recovery,

                        'remarks' =>
                        $request->remarks,

                        // Snapshot
                        'calculation_amount' =>
                        $request->amount,

                        'calculation_recovery_type' =>
                        $request->recovery_type,

                        'calculation_particulars' =>
                        $request->particulars,

                        'calculation_date' =>
                        $parsedDate->toDateString(),

                        'calculation_number_of_installments' =>
                        $request->number_of_installments,

                        'calculation_first_month' =>
                        $request->first_month,

                        'calculation_first_year' =>
                        $request->first_year,

                        'calculation_last_month' =>
                        $request->last_month,

                        'calculation_last_year' =>
                        $request->last_year,
                    ]);

                    $penaltiesData[] = $penalty;
                }
            });

            return response()->json([
                'status' => 200,
                'message' =>
                count($penaltiesData) .
                    ' recoveries applied successfully',
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified penalty.
     */
    public function show($id)
    {
        $penalty = Penalty::with('employee')->find($id);

        if (!$penalty) {
            return response()->json([
                'status' => 404,
                'message' => 'Penalty not found'
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'data' => new PenaltyResource($penalty)
        ]);
    }

    /**
     * Update the specified penalty.
     */
    public function updateOLD(UpdatePenaltyRequest $request, $id)
    {
        try {
            $penalty = Penalty::find($id);

            if (!$penalty) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Penalty not found'
                ], 404);
            }

            $updateData = [];

            if ($request->filled('employee_id')) {
                $updateData['employee_id'] = $request->employee_id;
            }

            if ($request->filled('penalty_date')) {
                $parsedDate = Carbon::parse($request->penalty_date);
                $updateData['penalty_date'] = $parsedDate->toDateString();
                $updateData['month'] = $parsedDate->month;
                $updateData['year'] = $parsedDate->year;
            }

            if ($request->filled('reason')) {
                $updateData['reason'] = $request->reason;
            }

            if ($request->filled('amount')) {
                $updateData['amount'] = $request->amount;
            }

            $penalty->update($updateData);

            return response()->json([
                'status' => 200,
                'message' => 'Penalty updated successfully',
                'data' => new PenaltyResource($penalty->load('employee'))
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }
    public function update(UpdatePenaltyRequest $request, $id)
    {
        try {
            $penalty = Penalty::find($id);

            if (!$penalty) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Penalty not found',
                ], 404);
            }

            $updateData = [];

            if ($request->has('employee_id')) {
                $updateData['employee_id'] = $request->employee_id;
            }

            if ($request->has('penalty_date')) {
                $parsedDate = Carbon::parse($request->penalty_date);

                $updateData['penalty_date'] = $parsedDate->toDateString();
                $updateData['month'] = $parsedDate->month;
                $updateData['year'] = $parsedDate->year;
            }

            if ($request->has('recovery_type')) {
                $updateData['recovery_type'] = $request->recovery_type;
            }

            if ($request->has('reason')) {
                $updateData['reason'] = $request->reason;
            }

            if ($request->has('particulars')) {
                $updateData['particulars'] = $request->particulars;
            }

            if ($request->has('amount')) {
                $updateData['amount'] = $request->amount;
            }

            if ($request->has('show_cause_issued')) {
                $updateData['show_cause_issued'] =
                    $request->boolean('show_cause_issued');
            }

            if ($request->has('explanation_heard_in_presence')) {
                $updateData['explanation_heard_in_presence'] =
                    $request->explanation_heard_in_presence;
            }

            if ($request->has('number_of_installments')) {
                $updateData['number_of_installments'] =
                    $request->number_of_installments;
            }

            if ($request->has('first_month')) {
                $updateData['first_month'] = $request->first_month;
            }

            if ($request->has('first_year')) {
                $updateData['first_year'] = $request->first_year;
            }

            if ($request->has('last_month')) {
                $updateData['last_month'] = $request->last_month;
            }

            if ($request->has('last_year')) {
                $updateData['last_year'] = $request->last_year;
            }

            if ($request->has('date_of_complete_recovery')) {
                $updateData['date_of_complete_recovery'] =
                    $request->date_of_complete_recovery;
            }

            if ($request->has('remarks')) {
                $updateData['remarks'] = $request->remarks;
            }

            // IMPORTANT:
            // Do NOT update:
            //
            // calculation_amount
            // calculation_recovery_type
            // calculation_particulars
            // calculation_date
            // calculation_number_of_installments
            // calculation_first_month
            // calculation_first_year
            // calculation_last_month
            // calculation_last_year

            $penalty->update($updateData);

            return response()->json([
                'status' => 200,
                'message' => 'Recovery updated successfully',
                'data' => new PenaltyResource(
                    $penalty->fresh()->load('employee')
                ),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified penalty.
     */
    public function destroy($id)
    {
        $penalty = Penalty::find($id);

        if (!$penalty) {
            return response()->json([
                'status' => 404,
                'message' => 'Penalty not found'
            ], 404);
        }

        $penalty->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Penalty deleted successfully'
        ]);
    }

    /**
     * Upload penalties in bulk via Excel/CSV.
     */
    public function bulkUploadOLD(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|mimes:xlsx,xls,csv'
            ]);

            \Maatwebsite\Excel\Facades\Excel::import(
                new \App\Imports\PenaltyImport,
                $request->file('file')
            );

            return response()->json([
                'status' => 200,
                'message' => 'Penalties imported successfully'
            ]);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = [
                    'row' => $failure->row(),
                    'attribute' => $failure->attribute(),
                    'errors' => $failure->errors(),
                ];
            }

            // Extract headers parsed from sheet
            $parsedHeaders = [];
            try {
                $array = \Maatwebsite\Excel\Facades\Excel::toArray(
                    new \App\Imports\PenaltyImport,
                    $request->file('file')
                );
                if (!empty($array) && isset($array[0][0])) {
                    $parsedHeaders = array_keys($array[0][0]);
                }
            } catch (\Exception $ex) {
                // Ignore
            }

            return response()->json([
                'status' => 422,
                'message' => 'Excel validation failed',
                'errors' => $errors,
                'parsed_headers' => $parsedHeaders
            ], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
    public function bulkUpload(Request $request)
    {
        try {
            $request->validate([
                'file' => [
                    'required',
                    'file',
                    'mimes:xlsx,xls,csv',
                    'max:10240',
                ],
            ]);

            \Maatwebsite\Excel\Facades\Excel::import(
                new \App\Imports\PenaltyImport,
                $request->file('file')
            );

            return response()->json([
                'status' => 200,
                'message' => 'Penalties updated successfully from Excel.',
            ]);
        } catch (
            \Maatwebsite\Excel\Validators\ValidationException $e
        ) {
            $failures = $e->failures();

            $errors = [];

            foreach ($failures as $failure) {
                $errors[] = [
                    'row' => $failure->row(),
                    'attribute' => $failure->attribute(),
                    'errors' => $failure->errors(),
                    'values' => $failure->values(),
                ];
            }

            return response()->json([
                'status' => 422,
                'message' => 'Excel validation failed',
                'errors' => $errors,
            ], 422);
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
     * The salary the 25% cap is measured against: basic + overtime for
     * the period, the same basis payroll uses.
     */
    private function grossSalaryFor(Employee $employee, int $month, int $year): float
    {
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();

        $overtimeHours = round(
            app(\App\Services\WageRegisterService::class)
                ->overtimeSummary([$employee->id], $month, $year)[$employee->id] ?? 0,
            2
        );

        $rates = \App\Models\EmployeeWage::effectiveSet($periodEnd->toDateString());
        $rate = isset($rates[$employee->skill_category]) && $rates[$employee->skill_category]
            ? (float) $rates[$employee->skill_category]->overtime_rate
            : 0.0;

        return \App\Models\EmployeeWage::monthlyPay(
            $rates,
            $employee->skill_category,
            optional($employee->activePayroll)->basic_salary
        ) + round($overtimeHours * $rate, 2);
    }

    /**
     * Work out the installment plan for an amount about to be saved.
     *
     * The caller may choose when recovery starts; how many installments
     * it takes and when it ends are consequences of the 25% cap, not
     * inputs, so they are always computed here rather than trusted from
     * the request.
     */
    private function scheduleFor(
        Employee $employee,
        float $amount,
        Carbon $penaltyDate,
        ?int $firstMonth,
        ?int $firstYear
    ): array {
        $startMonth = $firstMonth ?: $penaltyDate->month;
        $startYear = $firstYear ?: $penaltyDate->year;

        $projection = app(\App\Services\LoanRecoveryService::class)
            ->projectSchedule(
                $employee->id,
                $this->grossSalaryFor($employee, $startMonth, $startYear),
                $amount,
                $penaltyDate->toDateString(),
                $startMonth,
                $startYear
            );

        return [
            'installment_amount' => $projection['installment_amount'],
            'number_of_installments' => $projection['number_of_installments'] ?: null,
            // Recovery can begin later than requested when older
            // recoveries are still consuming the monthly budget.
            'first_month' => $projection['first_month'] ?? $startMonth,
            'first_year' => $projection['first_year'] ?? $startYear,
            'last_month' => $projection['last_month'],
            'last_year' => $projection['last_year'],
            'date_of_complete_recovery' => $projection['date_of_complete_recovery'],
        ];
    }

    /**
     * Preview the recovery schedule for an amount before it is saved.
     *
     * The form calls this once the user has picked an employee and
     * typed an amount; the installment count and the first/last period
     * come back computed rather than being typed in by hand.
     */
    public function previewSchedule(Request $request)
    {
        try {
            $validated = $request->validate([
                'employee_id' => 'required|exists:employees,id',
                'amount' => 'required|numeric|min:0.01',
                'penalty_date' => 'required|date',
                'recovery_type' => ['nullable', Rule::in(RecoveryType::values())],
                'first_month' => 'nullable|integer|between:1,12',
                'first_year' => 'nullable|integer|between:1900,2200',
            ]);

            $employee = Employee::with('activePayroll')->find($validated['employee_id']);

            $start = Carbon::parse($validated['penalty_date']);
            $month = (int) ($validated['first_month'] ?? $start->month);
            $year = (int) ($validated['first_year'] ?? $start->year);

            $projection = app(\App\Services\LoanRecoveryService::class)
                ->projectSchedule(
                    $employee->id,
                    $this->grossSalaryFor($employee, $month, $year),
                    (float) $validated['amount'],
                    $validated['penalty_date'],
                    $month,
                    $year
                );

            /*
             * Only the fields the form actually fills in. The caller
             * already knows the employee, amount and type it sent, so
             * echoing them back is dead weight — and a long schedule
             * is hundreds of rows nobody asked for.
             */
            $data = [
                'installment_amount' => $projection['installment_amount'],
                'number_of_installments' => $projection['number_of_installments'],
                'first_month' => $projection['first_month'],
                'first_year' => $projection['first_year'],
                'last_month' => $projection['last_month'],
                'last_year' => $projection['last_year'],
                'date_of_complete_recovery' => $projection['date_of_complete_recovery'],
                'monthly_limit' => $projection['monthly_limit'],
                'fully_recoverable' => $projection['fully_recoverable'],
            ];

            // Opt in with ?with_schedule=1 for the month-by-month table.
            if ($request->boolean('with_schedule')) {
                $data['schedule'] = $projection['schedule'];
                $data['competing_recoveries'] = $projection['competing_recoveries'];
            }

            return response()->json([
                'status' => 200,
                'message' => 'Recovery schedule calculated',
                'data' => $data,
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

    public function recoveryTypes()
    {
        return response()->json([
            'status' => 200,
            'data' => [
                [
                    'value' => 'damage',
                    'label' => 'Damage',
                ],
                [
                    'value' => 'loss',
                    'label' => 'Loss',
                ],
                [
                    'value' => 'fine',
                    'label' => 'Fine',
                ],
                [
                    'value' => 'advance',
                    'label' => 'Advance',
                ],
                [
                    'value' => 'loans',
                    'label' => 'Loans',
                ],
            ],
        ]);
    }
    public function export(Request $request)
    {
        try {
            $filters = [
                'employee_id' => $request->employee_id,
                'recovery_type' => $request->recovery_type,
                'month' => $request->month,
                'year' => $request->year,
                'penalty_date' => $request->penalty_date,
            ];

            return Excel::download(
                new PenaltiesExport($filters),
                'penalties-' . now()->format('Y-m-d-H-i-s') . '.xlsx'
            );
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage(),
            ], 500);
        }
    }
}
