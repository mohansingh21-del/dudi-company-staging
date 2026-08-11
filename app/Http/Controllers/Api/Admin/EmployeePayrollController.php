<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Department;
use App\Models\EmployeePayroll;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeePayrollRequest;
use App\Http\Requests\UpdateEmployeePayrollRequest;
use App\Http\Resources\EmployeePayrollResource;
use App\Imports\EmployeeImport;
use Maatwebsite\Excel\Facades\Excel;

class EmployeePayrollController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $payrolls = EmployeePayroll::with([
                'employee.department'
            ]);

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $payrolls->whereHas('employee', function ($q) use ($search) {

                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('employee_code', 'LIKE', "%{$search}%")
                        ->orWhere('mobile', 'LIKE', "%{$search}%");
                });
            }

            // Employee Filter
            if ($request->filled('employee_id')) {

                $payrolls->where(
                    'employee_id',
                    $request->employee_id
                );
            }

            // Department Filter
            if ($request->filled('department_id')) {

                $payrolls->whereHas('employee', function ($q) use ($request) {

                    $q->where(
                        'department_id',
                        $request->department_id
                    );
                });
            }



            // Active Filter

            $payrolls = $payrolls
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Payroll configurations fetched successfully',
                'data' => EmployeePayrollResource::collection($payrolls),
                'pagination' => [
                    'current_page' => $payrolls->currentPage(),
                    'last_page' => $payrolls->lastPage(),
                    'per_page' => $payrolls->perPage(),
                    'total' => $payrolls->total(),
                    'from' => $payrolls->firstItem(),
                    'to' => $payrolls->lastItem(),
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
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function store(StoreEmployeePayrollRequest $request)
    {
        try {

            $data = $request->validated();

              // Check if payroll configuration already exists for this employee to prevent duplicate rows
            $alreadyExists = EmployeePayroll::where('employee_id', $data['employee_id'])->exists();
            if ($alreadyExists) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Payroll configuration already exists for this employee.'
                ], 422);
            }

            EmployeePayroll::create([
                'employee_id' => $data['employee_id'],
                'salary_type' => $data['salary_type'],
                'basic_salary' => $data['basic_salary'] ?? 0,
                'daily_wage' => $data['daily_wage'] ?? 0,
                'pf_applicable' => $data['pf_applicable'] ?? false,
                'pf_number' => $data['pf_number'] ?? null,
                'uan' => $data['uan'] ?? null,
                'esic_ip_number' => $data['esic_ip_number'] ?? null,
                'lwf_number' => $data['lwf_number'] ?? null,
                'pan' => $data['pan'] ?? null,
                // aadhaar_last4 and aadhaar_hash are derived by the model mutator
                'aadhaar_number' => $data['aadhaar_number'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account_number' => $data['bank_account_number'] ?? null,
                'ifsc_code' => $data['ifsc_code'] ?? null,
                'mess_deduction_applicable' => $data['mess_deduction_applicable'] ?? false,
                'other_deduction_appliacble' => $data['other_deduction_appliacble'] ?? false,
                'other_deduction' => $data['other_deduction'] ?? 0,
                'pf_amount' => $data['pf_amount'] ?? 0,
                'mess_deduction_amount' => $data['mess_deduction_amount'] ?? 0,
                'rest_days' => $data['rest_days'] ?? 0,
                'is_active' => true
            ]);


            return response()->json([
                'status' => 200,
                'message' => 'Payroll configuration created successfully'
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

            $payroll = EmployeePayroll::with('employee')
                ->find($id);

            if (!$payroll) {

                return response()->json([
                    'status' => 404,
                    'message' => 'Payroll configuration not found'
                ]);
            }

            return response()->json([
                'status' => 200,
                'data' => new EmployeePayrollResource($payroll)
            ]);

        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(
        UpdateEmployeePayrollRequest $request,
        $id
    ) {
        try {

            $payroll = EmployeePayroll::find($id);

            if (!$payroll) {

                return response()->json([
                    'status' => 404,
                    'message' => 'Payroll configuration not found'
                ]);
            }
$data = $request->validated();

//dd($data);
            $payroll->update($request->validated());

            return response()->json([
                'status' => 200,
                'message' => 'Payroll configuration updated successfully'
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

            $payroll = EmployeePayroll::find($id);

            if (!$payroll) {

                return response()->json([
                    'status' => 404,
                    'message' => 'Payroll configuration not found'
                ]);
            }

            $payroll->delete();

            return response()->json([
                'status' => 200,
                'message' => 'Payroll configuration deleted successfully'
            ]);

        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }
    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */

}
