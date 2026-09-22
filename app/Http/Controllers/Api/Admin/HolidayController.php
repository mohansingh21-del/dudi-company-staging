<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Holiday;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
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
        // site_id arrives as an array (site_id[]). One holiday row is stored per
        // site, so every report that filters holidays by site keeps working.
        $siteIds = $request->input('site_id');

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($request, $siteIds, &$created, &$skipped) {
            foreach ($siteIds as $siteId) {
                $exists = Holiday::where('site_id', $siteId)
                    ->whereDate('holiday_date', $request->holiday_date)
                    ->exists();

                if ($exists) {
                    $skipped[] = (int) $siteId;
                    continue;
                }

                $created[] = Holiday::create([
                    'holiday_name' => $request->holiday_name,
                    'holiday_date' => $request->holiday_date,
                    'holiday_type' => $request->holiday_type,
                    'site_id' => $siteId,
                    'is_active' => 1,
                ]);
            }
        });

        if (empty($created)) {
            return response()->json([
                'status' => 422,
                'message' => 'Holiday already exists on this date for the selected site(s)',
                'skipped_site_ids' => $skipped,
            ], 422);
        }

        $message = 'Holiday created for ' . count($created) . ' site(s)';

        if (!empty($skipped)) {
            $skippedNames = Site::whereIn('id', $skipped)->pluck('site_name')->implode(', ');
            $message .= '. Skipped (holiday already exists on this date): ' . $skippedNames;
        }

        return response()->json([
            'status' => 200,
            'message' => $message,
            'created_count' => count($created),
            'skipped_site_ids' => $skipped,
            'data' => HolidayResource::collection(collect($created)),
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

        // site_id arrives as an array. The record being edited keeps the first
        // site; each extra site gets its own row, same as on create.
        $siteIds = $request->input('site_id');
        $primarySiteId = array_shift($siteIds);

        $holidayDate = $request->holiday_date ?: optional($dept->holiday_date)->format('Y-m-d');

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($request, $dept, $siteIds, $primarySiteId, $holidayDate, &$created, &$skipped) {
            $dept->fill(array_filter([
                'holiday_name' => $request->holiday_name,
                'holiday_date' => $request->holiday_date,
                'holiday_type' => $request->holiday_type,
                'site_id'      => $primarySiteId,
            ], fn ($value) => !is_null($value)));

            $dept->save();

            foreach ($siteIds as $siteId) {
                $exists = Holiday::where('site_id', $siteId)
                    ->whereDate('holiday_date', $holidayDate)
                    ->where('id', '!=', $dept->id)
                    ->exists();

                if ($exists) {
                    $skipped[] = (int) $siteId;
                    continue;
                }

                $created[] = Holiday::create([
                    'holiday_name' => $request->holiday_name ?: $dept->holiday_name,
                    'holiday_date' => $holidayDate,
                    'holiday_type' => $request->holiday_type ?: $dept->holiday_type,
                    'site_id' => $siteId,
                    'is_active' => 1,
                ]);
            }
        });

        $message = 'Holiday updated successfully';

        if (!empty($created)) {
            $message .= '. Added for ' . count($created) . ' more site(s)';
        }

        if (!empty($skipped)) {
            $skippedNames = Site::whereIn('id', $skipped)->pluck('site_name')->implode(', ');
            $message .= '. Skipped (holiday already exists on this date): ' . $skippedNames;
        }

        return response()->json([
            'status' => 200,
            'message' => $message,
            'created_count' => count($created),
            'skipped_site_ids' => $skipped,
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

        // A holiday that has already passed is part of attendance/payroll history,
        // so it cannot be deactivated.
        if (!$request->status && $dept->holiday_date && $dept->holiday_date->lt(now()->startOfDay())) {
            return response()->json([
                'status' => 422,
                'message' => 'Past date holiday cannot be deactivated'
            ], 422);
        }

        $dept->is_active = $request->status ? 1 : 0;
        $dept->save();


        return response()->json([
            'status' => 200,
            'message' => 'Holiday status updated successfully'
        ]);
    }
}
