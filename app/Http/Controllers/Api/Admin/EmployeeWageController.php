<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBulkEmployeeWageRequest;
use App\Http\Requests\StoreEmployeeWageRequest;
use App\Http\Requests\UpdateEmployeeWageRequest;
use App\Http\Resources\EmployeeWageResource;
use App\Models\Employee;
use App\Models\EmployeeWage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The minimum wage rate master printed at the head of Form B, and the source
 * payroll reads basic salary from when it is assigned to an employee.
 */
class EmployeeWageController extends Controller
{
    /**
     * The revision dates, newest first — one row per effective_from, not per
     * skill category, because the four categories are always revised together.
     *
     * Expand a row through matrix?effective_on=<date> for the rates themselves.
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);

            $wages = EmployeeWage::query();

            if ($request->filled('skill_category')) {
                $wages->where('skill_category', $request->skill_category);
            }

            if ($request->filled('is_active')) {
                $wages->where('is_active', $request->boolean('is_active'));
            }

            // Only the revisions already in force on a given date.
            if ($request->filled('effective_on')) {
                $wages->whereDate('effective_from', '<=', $request->effective_on);
            }

            $wages = $wages
                ->select('effective_from')
                ->groupBy('effective_from')
                ->orderByDesc('effective_from')
                ->paginate($limit);

            $today = now()->toDateString();

            // The one revision actually in force today: the latest that has
            // already taken effect. Everything dated later is still pending,
            // everything earlier has been superseded by it.
            $currentFrom = EmployeeWage::query()
                ->where('is_active', true)
                ->whereDate('effective_from', '<=', $today)
                ->max('effective_from');

            $currentFrom = $currentFrom
                ? Carbon::parse($currentFrom)->toDateString()
                : null;

            $rows = collect($wages->items())->map(function ($wage) use ($today, $currentFrom) {
                $effectiveFrom = $wage->effective_from->toDateString();

                if ($effectiveFrom === $currentFrom) {
                    $status = 'current';
                } elseif ($effectiveFrom > $today) {
                    $status = 'upcoming';
                } else {
                    $status = 'past';
                }

                return [
                    'effective_from' => $effectiveFrom,
                    'status' => $status,
                ];
            });

            return response()->json([
                'status' => 200,
                'message' => 'Wage rates fetched successfully',
                'data' => $rows,
                'pagination' => [
                    'current_page' => $wages->currentPage(),
                    'last_page' => $wages->lastPage(),
                    'per_page' => $wages->perPage(),
                    'total' => $wages->total(),
                    'from' => $wages->firstItem(),
                    'to' => $wages->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    /**
     * The Form B header grid: the rates in force on a date, one column per
     * skill category and one row per component.
     */
    public function matrix(Request $request)
    {
        try {
            $request->validate([
                'effective_on' => 'nullable|date'
            ]);

            $date = $request->input('effective_on', now()->toDateString());
            $set = EmployeeWage::effectiveSet($date);

            $columns = [];
            $rows = [
                'minimum_basic' => [],
                'dearness_allowance' => [],
                'overtime' => [],
            ];

            // The date the printed header reads "since" — the most recent
            // revision among the categories actually configured.
            $since = null;

            foreach ($set as $category => $wage) {
                $columns[] = [
                    'skill_category' => $category,
                    'label' => ucwords(str_replace('_', '-', $category), '-'),
                    'configured' => (bool) $wage,
                ];

                $rows['minimum_basic'][$category] = $wage ? $wage->minimum_basic : null;
                $rows['dearness_allowance'][$category] = $wage ? $wage->dearness_allowance : null;
                $rows['overtime'][$category] = $wage ? $wage->overtime_rate : null;

                if ($wage) {
                    $effective = $wage->effective_from->toDateString();

                    if (!$since || $effective > $since) {
                        $since = $effective;
                    }
                }
            }

            return response()->json([
                'status' => 200,
                'message' => 'Wage rate matrix fetched successfully',
                'data' => [
                    'as_on' => $date,
                    'since' => $since,
                    'columns' => $columns,
                    'rows' => $rows,
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    /**
     * The rate an employee falls under, so the payroll form can prefill basic
     * salary before it is submitted.
     */
    public function forEmployee(Request $request, $employeeId)
    {
        try {
            $request->validate([
                'effective_on' => 'nullable|date'
            ]);

            $employee = Employee::find($employeeId);

            if (!$employee) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Employee not found'
                ]);
            }

            if (!$employee->skill_category) {
                return response()->json([
                    'status' => 422,
                    'message' => 'This employee has no skill category set, so no wage rate applies.'
                ], 422);
            }

            $wage = EmployeeWage::effectiveFor(
                $employee->skill_category,
                $request->input('effective_on')
            );

            if (!$wage) {
                return response()->json([
                    'status' => 404,
                    'message' => 'No wage rate is configured for the ' . $employee->skill_category_label . ' category.'
                ]);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Wage rate fetched successfully',
                'data' => [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'skill_category' => $employee->skill_category,
                    'skill_category_label' => $employee->skill_category_label,
                    'wage' => new EmployeeWageResource($wage),
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function store(StoreEmployeeWageRequest $request)
    {
        try {
            $data = $request->validated();

            $wage = EmployeeWage::create([
                'skill_category' => $data['skill_category'],
                'minimum_basic' => $data['minimum_basic'],
                'dearness_allowance' => $data['dearness_allowance'],
                'overtime_rate' => $data['overtime_rate'] ?? 0,
                'effective_from' => $data['effective_from'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Wage rate created successfully',
                'data' => new EmployeeWageResource($wage)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    /**
     * The whole Form B header in one submit: every skill category for a single
     * revision date.
     *
     * Re-submitting a date that already carries a revision is rejected, so a
     * rate is never overwritten by accident. The load-edit-save-again flow
     * passes overwrite=true to say the replacement is deliberate.
     */
    public function bulkStore(StoreBulkEmployeeWageRequest $request)
    {
        try {
            $data = $request->validated();

            $effectiveFrom = $data['effective_from'];
            $isActive = $data['is_active'] ?? true;

            // Read before the write, so the response can name what it replaced.
            $overwritten = $request->clashingCategories();

            $saved = DB::transaction(function () use ($data, $effectiveFrom, $isActive) {
                $rows = [];

                foreach ($data['rates'] as $rate) {
                    $rows[] = EmployeeWage::updateOrCreate(
                        [
                            'skill_category' => $rate['skill_category'],
                            'effective_from' => $effectiveFrom,
                        ],
                        [
                            'minimum_basic' => $rate['minimum_basic'],
                            'dearness_allowance' => $rate['dearness_allowance'],
                            'overtime_rate' => $rate['overtime_rate'] ?? 0,
                            'is_active' => $isActive,
                        ]
                    );
                }

                return $rows;
            });

            $created = collect($saved)->filter(fn ($wage) => $wage->wasRecentlyCreated);

            return response()->json([
                'status' => 200,
                'message' => $overwritten->isEmpty()
                    ? 'Wage rates saved successfully'
                    : 'Wage rates saved successfully, replacing the revision already held from '
                        . Carbon::parse($effectiveFrom)->format('d M Y') . '.',
                'data' => [
                    'effective_from' => $effectiveFrom,
                    'created' => $created->count(),
                    'updated' => count($saved) - $created->count(),
                    'overwritten' => $overwritten->values(),
                    'rates' => EmployeeWageResource::collection(collect($saved)),
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function show($id)
    {
        try {
            $wage = EmployeeWage::find($id);

            if (!$wage) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Wage rate not found'
                ]);
            }

            return response()->json([
                'status' => 200,
                'data' => new EmployeeWageResource($wage)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    /**
     * Edits correct a revision in place. To change rates going forward, create
     * a new revision instead so the earlier one still prints on past registers.
     */
    public function update(UpdateEmployeeWageRequest $request, $id)
    {
        try {
            $wage = EmployeeWage::find($id);

            if (!$wage) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Wage rate not found'
                ]);
            }

            $wage->update($request->validated());

            return response()->json([
                'status' => 200,
                'message' => 'Wage rate updated successfully',
                'data' => new EmployeeWageResource($wage->fresh())
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function destroy($id)
    {
        try {
            $wage = EmployeeWage::find($id);

            if (!$wage) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Wage rate not found'
                ]);
            }

            $wage->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Wage rate deleted successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function toggleStatus(Request $request, $id)
    {
        $wage = EmployeeWage::find($id);

        if (!$wage) {
            return response()->json([
                'status' => 404,
                'message' => 'Wage rate not found'
            ]);
        }

        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $wage->is_active = $request->status ? 1 : 0;
        $wage->save();

        return response()->json([
            'status' => 200,
            'message' => 'Wage rate status updated successfully'
        ]);
    }
}
