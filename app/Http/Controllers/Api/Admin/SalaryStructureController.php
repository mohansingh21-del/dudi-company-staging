<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SalaryStructure;
use App\Http\Requests\StoreSalaryStructureRequest;
use App\Http\Requests\UpdateSalaryStructureRequest;
use App\Http\Resources\SalaryStructureResource;

class SalaryStructureController extends Controller
{
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $salaryStructures = SalaryStructure::with('designation');

            if ($request->filled('search')) {

                $search = $request->search;

                $salaryStructures->where(function ($query) use ($search) {
                    $query->whereHas('designation', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    })
                        ->orWhere('basic_salary', 'LIKE', "%{$search}%")
                        ->orWhere('shift_allowance', 'LIKE', "%{$search}%")
                        ->orWhere('incentives', 'LIKE', "%{$search}%");
                });
            }

            $salaryStructures = $salaryStructures
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'SalaryStructure list fetched successfully',
                'data' => SalaryStructureResource::collection($salaryStructures),
                'pagination' => [
                    'current_page' => $salaryStructures->currentPage(),
                    'last_page' => $salaryStructures->lastPage(),
                    'per_page' => $salaryStructures->perPage(),
                    'total' => $salaryStructures->total(),
                    'from' => $salaryStructures->firstItem(),
                    'to' => $salaryStructures->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }
    public function store(StoreSalaryStructureRequest $request)
    {
        $dept = SalaryStructure::create([
            'designation_id' => $request->designation_id,
            'basic_salary' => $request->basic_salary,
            'shift_allowance' => $request->shift_allowance,
            'incentives' => $request->incentives,
            'pf_applicable' => $request->pf_applicable,
            'mess_deduction_applicable' => $request->mess_deduction_applicable,
            'other_deduction' => $request->other_deduction,
            'status' => 1
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Salary Structure created'
            // 'data' => new DepartmentResource($dept)
        ]);
    }

    public function show(int $id)
    {
        $dept = SalaryStructure::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Salary Structure not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new SalaryStructureResource($dept)
        ]);
    }
    public function update(UpdateSalaryStructureRequest $request, int $id)
    {
        $dept = SalaryStructure::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Salary Structure not found'
            ]);
        }

        $dept->update([
            'designation_id' => $request->designation_id,
            'basic_salary' => $request->basic_salary,
            'shift_allowance' => $request->shift_allowance,
            'incentives' => $request->incentives,
            'pf_applicable' => $request->pf_applicable,
            'mess_deduction_applicable' => $request->mess_deduction_applicable,
            'other_deduction' => $request->other_deduction,

        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Salary Structure updated successfully'
        ]);
    }

    public function destroy(int $id)
    {
        $dept = SalaryStructure::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Department not found'
            ]);
        }

        $dept->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Department deleted successfully'
        ]);
    }
    public function toggleStatus(Request $request, int $id)
    {
        $dept = SalaryStructure::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Salary Structure not found'
            ]);
        }
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'Salary Structure status updated successfully'
        ]);
    }
}
