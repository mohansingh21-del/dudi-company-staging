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
        // `status` was being passed here and silently dropped — it is neither a
        // column nor fillable. New rows only looked active because the column
        // defaults to 1; is_active is now set on purpose.
        $dept = Holiday::create([
            'holiday_name' => $request->holiday_name,
            'holiday_date' => $request->holiday_date,
            'holiday_type' => $request->holiday_type,
            'site_id' => $request->site_id,
            'is_active' => 1,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Holiday created',
            'data' => new HolidayResource($dept->load('site')),
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

        // Only the keys actually submitted are applied. The old array_filter
        // dropped every null, which made site_id impossible to clear — a
        // site holiday could never be turned back into a general one.
        $payload = $request->only(['holiday_name', 'holiday_date', 'holiday_type', 'site_id']);

        $dept->fill($payload);
        $dept->save();

        return response()->json([
            'status' => 200,
            'message' => 'Holiday updated successfully',
            'data' => new HolidayResource($dept->load('site')),
        ]);
    }

    public function destroy(int $id)
    {
        $dept = Holiday::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Holiday not found'
            ]);
        }

        $dept->delete();

        return response()->json([
            'status' => 200,
            'message' => 'Holiday deleted successfully'
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

        // Re-activating is a duplicate risk the store/update rules never see:
        // the row is already saved, and the DB index only constrains active
        // rows. Without this check, switching an archived duplicate back on
        // would surface as a raw integrity-constraint error.
        if ($request->status && !$dept->is_active) {
            $clash = Holiday::active()
                ->whereDate('holiday_date', $dept->holiday_date)
                ->where('id', '!=', $dept->id)
                ->when(
                    $dept->site_id === null,
                    fn ($q) => $q->whereNull('site_id'),
                    fn ($q) => $q->where('site_id', $dept->site_id)
                )
                ->exists();

            if ($clash) {
                return response()->json([
                    'status' => 422,
                    'message' => 'A holiday already exists for this site on this date.',
                ], 422);
            }
        }

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'Holiday status updated successfully'
        ]);
    }
}
