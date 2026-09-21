<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeShiftAssignmentResource;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Http\Request;

class EmployeeShiftAssignmentController extends Controller
{
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 10);
            $query = EmployeeShiftAssignment::with(['employee', 'shift']);

            // Filters
            if ($request->filled('employee_id')) {
                $query->where('employee_id', $request->employee_id);
            }

            if ($request->filled('shift_id')) {
                $query->where('shift_id', $request->shift_id);
            }

            if ($request->filled('department_id')) {
                $query->whereHas('employee', function ($q) use ($request) {
                    $q->where('department_id', $request->department_id);
                });
            }

            if ($request->filled('site_id')) {
                $query->whereHas('employee', function ($q) use ($request) {
                    $q->where('site_id', $request->site_id);
                });
            }

            // Search by employee name, employee code or shift name
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('employee', function ($empQuery) use ($search) {
                        $empQuery->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('employee_code', 'LIKE', "%{$search}%");
                    })->orWhereHas('shift', function ($shiftQuery) use ($search) {
                        $shiftQuery->where('shift_name', 'LIKE', "%{$search}%");
                    });
                });
            }

            $assignments = $query->latest()->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Employee shift assignments fetched successfully',
                'data' => EmployeeShiftAssignmentResource::collection($assignments),
                'pagination' => [
                    'current_page' => $assignments->currentPage(),
                    'last_page' => $assignments->lastPage(),
                    'per_page' => $assignments->perPage(),
                    'total' => $assignments->total(),
                    'from' => $assignments->firstItem(),
                    'to' => $assignments->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'employee_id' => 'required_without:employee_ids',
                'employee_ids' => 'required_without:employee_id|array',
                'shift_id' => ['required', 'exists:shifts,id', new \App\Rules\ActiveShift()],
            ]);

            $shiftId = $request->shift_id;

            $identifiers = $request->has('employee_ids')
                ? $request->employee_ids
                : [$request->employee_id];

            $resolvedEmployeeIds = [];

            foreach ($identifiers as $identifier) {
                // Find employee by id first, then by employee_code
                $employee = \App\Models\Employee::with('relay')
                    ->where('id', $identifier)
                    ->orWhere('employee_code', $identifier)
                    ->first();

                if (!$employee) {
                    return response()->json([
                        'status' => 422,
                        'message' => "The selected employee identifier '{$identifier}' is invalid."
                    ], 422);
                }

                if (!$employee->relay_id || !$employee->relay || !$employee->relay->is_rotating) {
                    return response()->json([
                        'status' => 422,
                        'message' => "Shift assignment is not allowed for non-rotating/general shift employee '{$employee->name}'."
                    ], 422);
                }

                $resolvedEmployeeIds[] = $employee->id;
            }

            foreach ($resolvedEmployeeIds as $empId) {
                EmployeeShiftAssignment::updateOrCreate(
                    ['employee_id' => $empId],
                    [
                        'shift_id' => $shiftId,
                        'from_date' => now()->toDateString(),
                        'to_date' => null
                    ]
                );
            }

            $message = count($resolvedEmployeeIds) > 1
                ? 'Shift assigned successfully to employees'
                : 'Shift assigned successfully to employee';

            return response()->json([
                'status' => 200,
                'message' => $message
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function show(int $id)
    {
        try {
            $assignment = EmployeeShiftAssignment::with(['employee', 'shift'])->find($id);

            if (!$assignment) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Shift assignment not found'
                ]);
            }

            return response()->json([
                'status' => 200,
                'data' => new EmployeeShiftAssignmentResource($assignment)
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $assignment = EmployeeShiftAssignment::find($id);

            if (!$assignment) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Shift assignment not found'
                ]);
            }

            $request->validate([
                'employee_id' => 'sometimes|required',
                'shift_id' => ['sometimes', 'required', 'exists:shifts,id', new \App\Rules\ActiveShift($assignment->shift_id)],
            ]);

            $updateData = [];

            if ($request->has('employee_id')) {
                $employee = \App\Models\Employee::with('relay')->where('id', $request->employee_id)
                    ->orWhere('employee_code', $request->employee_id)
                    ->first();

                if (!$employee) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'The selected employee is invalid.'
                    ], 422);
                }

                if (!$employee->relay_id || !$employee->relay || !$employee->relay->is_rotating) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Shift assignment is not allowed for non-rotating/general shift employees.'
                    ], 422);
                }

                $updateData['employee_id'] = $employee->id;
            }

            if ($request->has('shift_id')) {
                $updateData['shift_id'] = $request->shift_id;
            }

            $assignment->update($updateData);

            return response()->json([
                'status' => 200,
                'message' => 'Shift assignment updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function destroy(int $id)
    {
        try {
            $assignment = EmployeeShiftAssignment::find($id);

            if (!$assignment) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Shift assignment not found'
                ]);
            }

            $assignment->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Shift assignment deleted successfully'
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function bulkUpload(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|mimes:xlsx,xls,csv'
            ]);

            $import = new \App\Imports\EmployeeShiftAssignmentImport;
            \Maatwebsite\Excel\Facades\Excel::import($import, $request->file('file'));

            $errors = $import->getErrors();
            $warnings = $import->getWarnings();
            $successCount = $import->getSuccessCount();

            $responseData = [
                'status' => 200,
                'message' => "Successfully assigned {$successCount} shifts."
            ];

            if (count($errors) > 0) {
                $responseData['message'] = "Import completed with some issues. Successfully assigned {$successCount} shifts.";
                $responseData['errors'] = $errors;
            }

            if (count($warnings) > 0) {
                $responseData['warnings'] = $warnings;
            }

            return response()->json($responseData);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    /**
     * Validation errors are caught here instead of by the generic 500 handler.
     */
    private function validationFailed(\Illuminate\Validation\ValidationException $e)
    {
        return response()->json([
            'status' => 422,
            'message' => $e->validator->errors()->first(),
            'errors' => $e->errors(),
        ], 422);
    }
}
