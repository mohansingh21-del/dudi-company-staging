<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Holiday;
use App\Http\Requests\StoreHolidayRequest;
use App\Http\Requests\UpdateHolidayRequest;
use App\Http\Resources\HolidayResource;

class HolidayController extends Controller
{
    public function index(Request $request)
    {

        try {

            $limit = $request->input('limit', 10);

            $salaryStructures = Holiday::with('site');

            if ($request->filled('search')) {

                $search = $request->search;

                $salaryStructures->where(function ($query) use ($search) {
                    $query->whereHas('site', function ($q) use ($search) {
                        $q->where('site_name', 'LIKE', "%{$search}%");
                    })
                        ->orWhere('holiday_date', 'LIKE', "%{$search}%")
                        ->orWhere('holiday_type', 'LIKE', "%{$search}%");
                });
            }

            $salaryStructures = $salaryStructures
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Holiday list fetched successfully',
                'data' => HolidayResource::collection($salaryStructures),
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
        'message' => $th->getMessage(),
        'line' => $th->getLine(),
        'file' => $th->getFile(),
    ]);
}
    }
    public function store(StoreHolidayRequest $request)
    {
        $dept = Holiday::create([
            'holiday_name' => $request->holiday_name,
            'holiday_date' => $request->holiday_date,
            'holiday_type' => $request->holiday_type,
            'site_id' => $request->site_id,
            'status' => 1,

        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Holiday created'
            // 'data' => new DepartmentResource($dept)
        ]);
    }

    public function show(int $id)
    {
        //dd($id);
        $dept = Holiday::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Holiday not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new HolidayResource($dept)
        ]);
    }
    public function update(UpdateHolidayRequest $request, int $id)
    {
        $dept = Holiday::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Holiday not found'
            ]);
        }

        $dept->update([
            'holiday_name' => $request->holiday_name,
            'holiday_date' => $request->holiday_date,
            'holiday_type' => $request->holiday_type,
            'site' => $request->site_id,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Holiday updated successfully'
        ]);
    }

    public function destroy(int $id)
    {
        $dept = Holiday::find($id);

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
        $dept = Holiday::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Holiday not found'
            ]);
        }
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'Holiday status updated successfully'
        ]);
    }
}
