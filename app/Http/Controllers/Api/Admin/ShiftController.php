<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Shift;
use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;
use App\Http\Resources\ShiftResource;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $departments = Shift::query();

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $departments->where(function ($query) use ($search) {
                    $query->where('shift_name', 'LIKE', "%{$search}%");
                    //->orWhere('address', 'LIKE', "%{$search}%");
                });
            }

            $departments = $departments
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Shift list fetched successfully',
                'data' => ShiftResource::collection($departments),
                'pagination' => [
                    'current_page' => $departments->currentPage(),
                    'last_page' => $departments->lastPage(),
                    'per_page' => $departments->perPage(),
                    'total' => $departments->total(),
                    'from' => $departments->firstItem(),
                    'to' => $departments->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    public function store(StoreShiftRequest $request)
    {
        if ($this->isShiftOverlapping($request->start_time, $request->end_time)) {
            return response()->json([
                'status' => 422,
                'message' => 'Shift timings overlap with an existing shift.'
            ], 422);
        }

        $dept = Shift::create([
            'shift_name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'minimum_working_hours' => $request->minimum_working_hours,
            'is_night_shift' => $request->is_night_shift,
            'status' => 1
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Shift created'
            // 'data' => new DepartmentResource($dept)
        ]);
    }

    public function show(int $id)
    {
        $dept = Shift::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Shift not found'
            ]);
        }

        return response()->json([
            'status' => 200,
            'data' => new ShiftResource($dept)
        ]);
    }
    public function update(UpdateShiftRequest $request, int $id)
    {
        $dept = Shift::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Site not found'
            ]);
        }

        if ($this->isShiftOverlapping($request->start_time, $request->end_time, $id)) {
            return response()->json([
                'status' => 422,
                'message' => 'Shift timings overlap with an existing shift.'
            ], 422);
        }

        $dept->update([
            'shift_name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'minimum_working_hours' => $request->minimum_working_hours,
            'is_night_shift' => $request->is_night_shift
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Shift updated successfully'
        ]);
    }

    public function destroy(int $id)
    {
        $dept = Shift::find($id);

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
        $dept = Shift::find($id);

        if (!$dept) {
            return response()->json([
                'status' => 404,
                'message' => 'Shift not found'
            ]);
        }
        $request->validate([
            'status' => 'required|in:0,1'
        ]);

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'Shift status updated successfully'
        ]);
    }

    public function getPublicShifts(Request $request)
    {
        try {

            $limit = $request->input('limit', null);

            $departments = Shift::where('is_active', 1)->select('id', 'shift_name', 'is_active');

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $departments->where(function ($query) use ($search) {
                    $query->where('shift_name', 'LIKE', "%{$search}%");
                    //->orWhere('address', 'LIKE', "%{$search}%");
                });
            }

            $departments = $departments
                ->latest()
                ->paginate($limit);

            return response()->json([
                'status' => 200,
                'message' => 'Shift list fetched successfully',
                'data' => $departments->items(),
                'pagination' => [
                    'current_page' => $departments->currentPage(),
                    'last_page' => $departments->lastPage(),
                    'per_page' => $departments->perPage(),
                    'total' => $departments->total(),
                    'from' => $departments->firstItem(),
                    'to' => $departments->lastItem(),
                ]
            ]);
        } catch (\Throwable $th) {

            return response()->json([
                'status' => 500,
                'message' => $th->getMessage()
            ]);
        }
    }

    private function isShiftOverlapping($startTime, $endTime, $excludeId = null)
    {
        $timeToMinutes = function ($time) {
            $parts = explode(':', $time);
            return intval($parts[0]) * 60 + intval($parts[1]);
        };

        $getMinutesRange = function ($startStr, $endStr) use ($timeToMinutes) {
            $start = $timeToMinutes($startStr);
            $end = $timeToMinutes($endStr);
            $minutes = [];
            if ($start < $end) {
                for ($m = $start; $m < $end; $m++) {
                    $minutes[$m] = true;
                }
            } else {
                for ($m = $start; $m < 1440; $m++) {
                    $minutes[$m] = true;
                }
                for ($m = 0; $m < $end; $m++) {
                    $minutes[$m] = true;
                }
            }
            return $minutes;
        };

        $newRange = $getMinutesRange($startTime, $endTime);

        $query = Shift::where('is_active', 1);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        $existingShifts = $query->get();

        foreach ($existingShifts as $existingShift) {
            $existingRange = $getMinutesRange($existingShift->start_time, $existingShift->end_time);
            foreach ($newRange as $min => $val) {
                if (isset($existingRange[$min])) {
                    return true;
                }
            }
        }

        return false;
    }
}
