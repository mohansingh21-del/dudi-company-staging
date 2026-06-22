<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Imports\EmployeeImport;
use Maatwebsite\Excel\Facades\Excel;

class EmployeeController extends Controller
{ /* |-------------------------------------------------------------------------- | Employee List |-------------------------------------------------------------------------- */
    public function index_OLDDDD(Request $request)
    {
        try {
            $employees = Employee::with(['department', 'designation', 'site', 'supervisor']); /* |-------------------------------------------------------------------------- | Search |-------------------------------------------------------------------------- */
            if ($request->filled('search')) {
                $search = $request->search;
                $employees->where(function ($query) use ($search) {
                    $query->where('name', 'LIKE', "%{$search}%")->orWhere('employee_code', 'LIKE', "%{$search}%")->orWhere('mobile', 'LIKE', "%{$search}%");
                });
            } /* |-------------------------------------------------------------------------- | Department Filter |-------------------------------------------------------------------------- */
            if ($request->filled('department_id')) {
                $employees->where('department_id', $request->department_id);
            } /* |-------------------------------------------------------------------------- | Site Filter |-------------------------------------------------------------------------- */
            if ($request->filled('site_id')) {
                $employees->where('site_id', $request->site_id);
            } /* |-------------------------------------------------------------------------- | Status Filter |-------------------------------------------------------------------------- */
            if ($request->filled('status')) {
                $employees->where('status', $request->status);
            }
            $employees = $employees->latest()->paginate(20);
            return response()->json(['status' => 200, 'message' => 'Employee list fetched successfully', 'data' => EmployeeResource::collection($employees)]);
        } catch (\Throwable $th) {
            return response()->json(['status' => 500, 'message' => $th->getMessage()]);
        }
    } /* |-------------------------------------------------------------------------- | Store Employee |-------------------------------------------------------------------------- */

    public function index_oldd(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $employees = Employee::with([
                'department',
                'designation',
                'site',
                'supervisor'
            ]);

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $employees->where(function ($query) use ($search) {

                    $query->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('employee_code', 'LIKE', "%{$search}%")
                        ->orWhere('mobile', 'LIKE', "%{$search}%")

                        // Department name search
                        ->orWhereHas('department', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        })

                        // Designation name search
                        ->orWhereHas('designation', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        });
                });
            }

            // Department Filter
            if ($request->filled('department_id')) {
                $employees->where('department_id', $request->department_id);
            }
            if ($request->filled('designation_id')) {
                $employees->where('designation_id', $request->designation_id);
            }

            // Site Filter
            if ($request->filled('site_id')) {
                $employees->where('site_id', $request->site_id);
            }

            // Status Filter
            if ($request->filled('status')) {
                $employees->where('status', $request->status);
            }
            $excludedRelayShifts = ['general']; // relay_shift values to exclude


            $employees = $employees->whereNotIn('relay_shift', $excludedRelayShifts)
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Employee list fetched successfully',
                'data' => EmployeeResource::collection($employees),
                'pagination' => [
                    'current_page' => $employees->currentPage(),
                    'last_page' => $employees->lastPage(),
                    'per_page' => $employees->perPage(),
                    'total' => $employees->total(),
                    'from' => $employees->firstItem(),
                    'to' => $employees->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }
    public function index(Request $request)
    {
        try {

            $employees = Employee::with([
                'department',
                'designation',
                'site',
                'supervisor'
            ]);

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $employees->where(function ($query) use ($search) {

                    $query->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('employee_code', 'LIKE', "%{$search}%")
                        ->orWhere('mobile', 'LIKE', "%{$search}%")
                        ->orWhereHas('department', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('designation', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        });
                });
            }

            // Filters
            if ($request->filled('department_id')) {
                $employees->where('department_id', $request->department_id);
            }

            if ($request->filled('designation_id')) {
                $employees->where('designation_id', $request->designation_id);
            }

            if ($request->filled('site_id')) {
                $employees->where('site_id', $request->site_id);
            }

            if ($request->filled('status')) {
                $employees->where('status', $request->status);
            }

            $employees = $employees->latest();

            // If limit exists => paginate
            if ($request->filled('limit')) {

                $employees = $employees->paginate($request->limit);

                return response()->json([
                    'status' => 200,
                    'message' => 'Employee list fetched successfully',
                    'data' => EmployeeResource::collection($employees),
                    'pagination' => [
                        'current_page' => $employees->currentPage(),
                        'last_page' => $employees->lastPage(),
                        'per_page' => $employees->perPage(),
                        'total' => $employees->total(),
                        'from' => $employees->firstItem(),
                        'to' => $employees->lastItem(),
                    ]
                ]);
            }

            // No limit => return all data
            $employees = $employees->get();

            return response()->json([
                'status' => 200,
                'message' => 'Employee list fetched successfully',
                'data' => EmployeeResource::collection($employees)
            ]);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }
    public function store(StoreEmployeeRequest $request)
    {
        ///////dd($request->all());
        // dd([
        //     'content_type' => $request->header('Content-Type'),
        //     'accept' => $request->header('Accept'),
        //     'raw' => $request->getContent(),
        //     'all' => $request->all(),
        //     'input' => $request->input(),
        // ]);
        $data = $request->validated();

        $data['dob'] = \Carbon\Carbon::createFromFormat('d/m/Y', $data['dob'])->format('Y-m-d');
        $data['joining_date'] = \Carbon\Carbon::createFromFormat('d/m/Y', $data['joining_date'])->format('Y-m-d');
        try {
            //  dd($request->all());
            $employee = Employee::create($data);
            return response()->json(['status' => 200, 'message' => 'Employee created successfully']);
        } catch (\Throwable $th) {
            return response()->json(['status' => 500, 'message' => $th->getMessage()]);
        }
    } /* |-------------------------------------------------------------------------- | Show Employee |-------------------------------------------------------------------------- */
    public function bulkUpload(Request $request)
    {
        try {

            $request->validate([
                'file' => 'required|mimes:xlsx,xls,csv'
            ]);

            Excel::import(
                new EmployeeImport(),
                $request->file('file')
            );

            return response()->json([
                'status' => 200,
                'message' => 'Employees imported successfully'
            ]);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {

            $formattedErrors = [];

            foreach ($e->errors() as $row => $messages) {

                foreach ($messages as $message) {

                    $formattedErrors[] = [
                        'row' => str_replace('*.', '', $row),
                        'message' => $message
                    ];
                }
            }

            return response()->json([
                'status' => 422,
                'message' => 'Excel validation failed.',
                'errors' => $formattedErrors
            ], 422);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {

            $errors = [];

            foreach ($e->failures() as $failure) {

                $errors[] = [
                    'row' => $failure->row(),
                    'column' => $failure->attribute(),
                    'message' => implode(', ', $failure->errors()),
                    'value' => $failure->values()[$failure->attribute()] ?? null,
                ];
            }

            return response()->json([
                'status' => 422,
                'message' => 'Excel validation failed.',
                'errors' => $errors
            ], 422);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
    public function show(int $id)
    {
        try {
            $employee = Employee::with(['department', 'designation', 'site', 'supervisor'])->find($id);
            if (!$employee) {
                return response()->json(['status' => 404, 'message' => 'Employee not found']);
            }
            return response()->json(['status' => 200, 'data' => new EmployeeResource($employee)]);
        } catch (\Throwable $th) {
            return response()->json(['status' => 500, 'message' => $th->getMessage()]);
        }
    } /* |-------------------------------------------------------------------------- | Update Employee |-------------------------------------------------------------------------- */
    public function update(UpdateEmployeeRequest $request, int $id)
    {
        //// dd($request->all());
        try {
            $employee = Employee::find($id);
            if (!$employee) {
                return response()->json(['status' => 404, 'message' => 'Employee not found']);
            }
            $data = $request->validated();

            $data['dob'] = \Carbon\Carbon::createFromFormat('d/m/Y', $data['dob'])->format('Y-m-d');
            $data['joining_date'] = \Carbon\Carbon::createFromFormat('d/m/Y', $data['joining_date'])->format('Y-m-d');

            $employee->update($data);
            return response()->json(['status' => 200, 'message' => 'Employee updated successfully']);
        } catch (\Throwable $th) {
            return response()->json(['status' => 500, 'message' => $th->getMessage()]);
        }
    } /* |-------------------------------------------------------------------------- | Delete Employee |-------------------------------------------------------------------------- */
    public function destroy(int $id)
    {
        try {
            $employee = Employee::find($id);
            if (!$employee) {
                return response()->json(['status' => 404, 'message' => 'Employee not found']);
            }
            $employee->delete();
            return response()->json(['status' => 200, 'message' => 'Employee deleted successfully']);
        } catch (\Throwable $th) {
            return response()->json(['status' => 500, 'message' => $th->getMessage()]);
        }
    }

    public function toggleStatus(Request $request, int $id)
    {
        ////dd($request->all());
        try {
            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Employee not found'
                ]);
            }

            $request->validate([
                'status' => 'required|in:0,1'
            ]);

            $employee->is_active = $request->status;
            $employee->save();

            return response()->json([
                'status' => 200,
                'message' => 'Employee status updated successfully',

            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function getPublicEmployees(Request $request, $id = null)
    {
        if ($request->has('role')) {
            $request->validate([
                'role' => 'required|string|in:Supervisor,Site Incharge,supervisor,site-incharge,site_incharge'
            ]);

            try {
                $roleName = $request->input('role');
                $targetSlug = \Illuminate\Support\Str::slug($roleName);

                $role = \App\Models\Role::where('slug', $targetSlug)
                    ->orWhere('slug', $roleName)
                    ->orWhere('name', $roleName)
                    ->first();

                if (!$role) {
                    return response()->json([
                        'status' => 404,
                        'message' => 'Role not found'
                    ], 404);
                }

                $query = Employee::where('designation_id', $role->id)
                    ->where('is_active', 1)
                    ->with(['department', 'designation', 'site', 'supervisor']);

                if ($request->filled('search')) {
                    $search = $request->search;
                    $query->where(function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('employee_code', 'LIKE', "%{$search}%");
                    });
                }

                if ($request->filled('department_id')) {
                    $query->where('department_id', $request->department_id);
                }

                if ($request->filled('site_id')) {
                    $query->where('site_id', $request->site_id);
                }

                $employees = $query->latest()->get();

                return response()->json([
                    'status' => 200,
                    'message' => 'Employees fetched successfully',
                    'data' => EmployeeResource::collection($employees)
                ]);
            } catch (\Throwable $th) {
                return response()->json([
                    'status' => 500,
                    'message' => $th->getMessage()
                ], 500);
            }
        }

        try {

            $excludedRelayShifts = ['general'];

            $limit = $request->input('limit', 10);


            if ($id !== null) {

                $employees = Employee::where('is_active', 1)
                    ->whereNotIn('relay_shift', $excludedRelayShifts)
                    ->whereHas('shiftAssignments', function ($query) use ($id) {

                        $query->where('shift_id', $id);
                    });
                $employees = Employee::where('is_active', 1)->whereNotIn('relay_shift', $excludedRelayShifts)->whereHas('shiftAssignments', function ($query) use ($id) {
                    $query->where('shift_id', $id);
                });
            } else {

                $employees = Employee::where('is_active', 1)
                    ->whereNotIn('relay_shift', $excludedRelayShifts)
                    ->whereDoesntHave('shiftAssignments');
            }


            // Department filter
            if ($request->filled('department_id')) {

                $employees->where(
                    'department_id',
                    $request->department_id
                );
            }


            // Search
            if ($request->filled('search')) {

                $search = $request->search;


                $employees->where(function ($query) use ($search) {

                    $query->where(
                        'name',
                        'LIKE',
                        "%{$search}%"
                    )
                        ->orWhere(
                            'employee_code',
                            'LIKE',
                            "%{$search}%"
                        );
                });
            }



            // Load designation relationship
            // Include foreign key for relation
            $employees->with('designation')
                ->select(
                    'id',
                    'name',
                    'employee_code',
                    'is_active',
                    'designation_id' // change to designation_id if your column is different
                );



            $employees = $employees
                ->latest()
                ->paginate($limit);



            $employees->through(function ($employee) {

                return [

                    'id' => $employee->id,

                    'name' => $employee->name,

                    'employee_code' => $employee->employee_code,

                    'designation' => $employee->designation?->name

                ];
            });



            return response()->json([

                'status' => 200,

                'message' => 'Employee list fetched successfully',

                'data' => $employees->items(),

                'pagination' => [

                    'current_page' => $employees->currentPage(),

                    'last_page' => $employees->lastPage(),

                    'per_page' => $employees->perPage(),

                    'total' => $employees->total(),

                    'from' => $employees->firstItem(),

                    'to' => $employees->lastItem(),

                ]

            ]);
        } catch (\Throwable $th) {


            return response()->json([

                'status' => 500,

                'message' => $th->getMessage()

            ]);
        }
    }
    public function getActiveEmployees(Request $request)
    {
        try {
            $query = Employee::where('is_active', 1);

            // Optional Search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('employee_code', 'LIKE', "%{$search}%");
                });
            }

            // Optional Department Filter
            if ($request->filled('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            // Optional Site Filter
            if ($request->filled('site_id')) {
                $query->where('site_id', $request->site_id);
            }

            // Return limited/paginated active employees if limit parameter exists
            if ($request->filled('limit')) {
                $employees = $query->paginate($request->limit);
                return response()->json([
                    'status' => 200,
                    'message' => 'Active employees fetched successfully',
                    'data' => $employees->items(),
                    'pagination' => [
                        'current_page' => $employees->currentPage(),
                        'last_page' => $employees->lastPage(),
                        'per_page' => $employees->perPage(),
                        'total' => $employees->total(),
                        'from' => $employees->firstItem(),
                        'to' => $employees->lastItem(),
                    ]
                ]);
            }

            $employees = $query->latest()->get(['id', 'name', 'employee_code']);

            return response()->json([
                'status' => 200,
                'message' => 'Active employees fetched successfully',
                'data' => $employees
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ], 500);
        }
    }
}
