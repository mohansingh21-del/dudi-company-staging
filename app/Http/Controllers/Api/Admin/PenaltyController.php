<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Penalty;
use App\Models\Employee;
use App\Http\Requests\StorePenaltyRequest;
use App\Http\Requests\StoreBulkPenaltyRequest;
use App\Http\Requests\UpdatePenaltyRequest;
use App\Http\Resources\PenaltyResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
                      ->orWhereHas('employee', function ($empQuery) use ($search) {
                          $empQuery->where('name', 'LIKE', "%{$search}%")
                                   ->orWhere('employee_code', 'LIKE', "%{$search}%");
                      });
                });
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
    public function store(StorePenaltyRequest $request)
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

    /**
     * Store multiple penalties in bulk.
     */
    public function storeBulk(StoreBulkPenaltyRequest $request)
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
    public function update(UpdatePenaltyRequest $request, $id)
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
    public function bulkUpload(Request $request)
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
}
