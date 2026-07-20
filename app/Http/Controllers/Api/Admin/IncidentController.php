<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\UpdateIncidentRequest;
use App\Http\Resources\IncidentResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $incidents = Incident::with([
                'shift',
                'incidentType',
                'location',
                'equipment',
                'equipmentName'
            ]);

            /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        */
            if ($request->filled('search')) {

                $search = $request->search;

                $incidents->where(function ($query) use ($search) {

                    $query->where('incident_no', 'LIKE', "%{$search}%")
                        ->orWhere('incident_description', 'LIKE', "%{$search}%")

                        ->orWhereHas('incidentType', function ($q) use ($search) {
                            $q->where('incident_type', 'LIKE', "%{$search}%");
                        })

                        ->orWhereHas('location', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('equipment', function ($q) use ($search) {
                            $q->where('name', 'LIKE', "%{$search}%");
                        })

                        ->orWhereHas('equipmentName', function ($q) use ($search) {
                            $q->where('equipment_name', 'LIKE', "%{$search}%");
                        });
                });
            }

            /*
        |--------------------------------------------------------------------------
        | DATE FILTERS
        |--------------------------------------------------------------------------
        */
            $dateFrom = $request->date_from
                ? Carbon::createFromFormat('d/m/Y', $request->date_from)->toDateString()
                : null;

            $dateTo = $request->date_to
                ? Carbon::createFromFormat('d/m/Y', $request->date_to)->toDateString()
                : null;

            if ($dateFrom) {
                $incidents->whereDate('incident_date', '>=', $dateFrom);
            }

            if ($dateTo) {
                $incidents->whereDate('incident_date', '<=', $dateTo);
            }
            /*
        |--------------------------------------------------------------------------
        | FILTERS
        |--------------------------------------------------------------------------
        */
            if ($request->filled('shift_id')) {
                $incidents->where('shift_id', $request->shift_id);
            }

            if ($request->filled('location_id')) {
                $incidents->where('location_id', $request->location_id);
            }

            if ($request->filled('incident_type_id')) {
                $incidents->where('incident_type_id', $request->incident_type_id);
            }
            if ($request->filled('equipment_id')) {
                $incidents->where('equipment_id', $request->equipment_id);
            }

            if ($request->filled('equipment_name_id')) {
                $incidents->where('equipment_name_id', $request->equipment_name_id);
            }

            /*
        |--------------------------------------------------------------------------
        | DASHBOARD BASE QUERY
        |--------------------------------------------------------------------------
        */
            $dashboardQuery = clone $incidents;

            $totalIncidents = (clone $dashboardQuery)->count();
            $totalSafe = max($totalIncidents, 1);

            /*
        |--------------------------------------------------------------------------
        | CURRENT STREAK (DAYS SINCE LAST INCIDENT)
        |--------------------------------------------------------------------------
        */
            $lastIncident = (clone $dashboardQuery)
                ->latest('incident_date')
                ->first();

            $currentStreak = $lastIncident
                ? Carbon::parse($lastIncident->incident_date)->diffInDays(now())
                : 0;

            /*
        |--------------------------------------------------------------------------
        | BEST HISTORICAL SAFETY RECORD (LONGEST GAP)
        |--------------------------------------------------------------------------
        */
            $dates = (clone $dashboardQuery)
                ->orderBy('incident_date', 'asc')
                ->pluck('incident_date');

            $longestStreak = 0;
            $previousDate = null;

            foreach ($dates as $date) {

                if ($previousDate) {

                    $diff = Carbon::parse($previousDate)
                        ->diffInDays(Carbon::parse($date));

                    if ($diff > $longestStreak) {
                        $longestStreak = $diff;
                    }
                }

                $previousDate = $date;
            }

            /*
        |--------------------------------------------------------------------------
        | KPIs
        |--------------------------------------------------------------------------
        */
            $criticalActionsOpen = (clone $dashboardQuery)
                ->where('status', 'Under Review')
                ->count();

            /*
        |--------------------------------------------------------------------------
        | SAFETY RECORD
        |--------------------------------------------------------------------------
        */
            $safetyRecord = 'Green';

            $hasCritical = (clone $dashboardQuery)
                ->where('severity', 'CRITICAL')
                ->exists();

            $hasHigh = (clone $dashboardQuery)
                ->where('severity', 'HIGH')
                ->exists();

            if ($hasCritical) {
                $safetyRecord = 'Red';
            } elseif ($hasHigh) {
                $safetyRecord = 'Yellow';
            }

            /*
        |--------------------------------------------------------------------------
        | INCIDENT DISTRIBUTION (WITH %)
        |--------------------------------------------------------------------------
        */
            $incidentDistribution = [];

            $incidentData = (clone $dashboardQuery)
                ->join('incident_types', 'incidents.incident_type_id', '=', 'incident_types.id')
                ->selectRaw('incident_types.incident_type, COUNT(*) as total')
                ->groupBy('incident_types.incident_type')
                ->get();

            foreach ($incidentData as $item) {

                $incidentDistribution[$item->incident_type] = [
                    'count' => (int) $item->total,
                    'percentage' => round(($item->total / $totalSafe) * 100, 2)
                ];
            }

            /*
        |--------------------------------------------------------------------------
        | SHIFT DISTRIBUTION
        |--------------------------------------------------------------------------
        */
            $shiftDistribution = [];

            $shiftData = (clone $dashboardQuery)
                ->join('shifts', 'incidents.shift_id', '=', 'shifts.id')
                ->selectRaw('shifts.shift_name, COUNT(*) as total')
                ->groupBy('shifts.shift_name')
                ->get();

            foreach ($shiftData as $item) {

                $shiftDistribution[$item->shift_name] = [
                    'count' => (int) $item->total,
                    'percentage' => round(($item->total / $totalSafe) * 100, 2)
                ];
            }

            /*
        |--------------------------------------------------------------------------
        | LOCATION DISTRIBUTION
        |--------------------------------------------------------------------------
        */
            $locationDistribution = [];

            $locationData = (clone $dashboardQuery)
                ->join('sites', 'incidents.location_id', '=', 'sites.id')
                ->selectRaw('sites.site_name, COUNT(*) as total')
                ->groupBy('sites.site_name')
                ->get();

            foreach ($locationData as $item) {

                $locationDistribution[$item->site_name] = [
                    'count' => (int) $item->total,
                    'percentage' => round(($item->total / $totalSafe) * 100, 2)
                ];
            }

            /*
        |--------------------------------------------------------------------------
        | SEVERITY DISTRIBUTION
        |--------------------------------------------------------------------------
        */
            $severityDistribution = [
                'LOW' => ['count' => 0, 'percentage' => 0],
                'MEDIUM' => ['count' => 0, 'percentage' => 0],
                'HIGH' => ['count' => 0, 'percentage' => 0],
                'CRITICAL' => ['count' => 0, 'percentage' => 0],
            ];

            $severityData = (clone $dashboardQuery)
                ->selectRaw('severity, COUNT(*) as total')
                ->groupBy('severity')
                ->get();

            foreach ($severityData as $item) {

                $severityDistribution[$item->severity] = [
                    'count' => (int) $item->total,
                    'percentage' => round(($item->total / $totalSafe) * 100, 2)
                ];
            }

            /*
        |--------------------------------------------------------------------------
        | STATUS DISTRIBUTION
        |--------------------------------------------------------------------------
        */
            $statusDistribution = [
                'Under Review' => ['count' => 0, 'percentage' => 0],
                'Investigation Closed' => ['count' => 0, 'percentage' => 0],
            ];

            $statusData = (clone $dashboardQuery)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->get();

            foreach ($statusData as $item) {

                $statusDistribution[$item->status] = [
                    'count' => (int) $item->total,
                    'percentage' => round(($item->total / $totalSafe) * 100, 2)
                ];
            }

            /*
        |--------------------------------------------------------------------------
        | PAGINATION (LIST ONLY)
        |--------------------------------------------------------------------------
        */
            $incidents = $incidents
                ->latest()
                ->paginate($limit);

            return response()->json([

                'status' => 200,
                'message' => 'Incident list fetched successfully',

                'dashboard' => [

                    'total_incidents' => $totalIncidents,

                    'days_since_last_incident' => $currentStreak,

                    'best_safety_record_days' => $longestStreak,

                    'safety_record' => $safetyRecord,

                    'critical_actions_open' => $criticalActionsOpen,

                    'incident_distribution' => $incidentDistribution,

                    'shift_distribution' => $shiftDistribution,

                    'location_distribution' => $locationDistribution,

                    'severity_distribution' => $severityDistribution,

                    'status_distribution' => $statusDistribution,
                ],

                'data' => IncidentResource::collection($incidents),

                'pagination' => [
                    'current_page' => $incidents->currentPage(),
                    'last_page' => $incidents->lastPage(),
                    'per_page' => $incidents->perPage(),
                    'total' => $incidents->total(),
                    'from' => $incidents->firstItem(),
                    'to' => $incidents->lastItem(),
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

        $incident = Incident::with([
            'shift',
            'incidentType',
            'location',
            'equipment',
            'equipmentName',
            'person',
            'media'
        ])->find($id);

        if (!$incident) {

            return response()->json([

                'status' => 404,

                'message' => 'Incident not found'

            ]);
        }

        return response()->json([

            'status' => 200,

            'data' => new IncidentResource($incident)

        ]);
    }
    public function close(Request $request, Incident $incident)
    {
        if ($incident->status === 'Investigation Closed') {
            return response()->json([
                'status' => 422,
                'message' => 'Incident is already closed.'
            ], 422);
        }

        $incident->update([
            'status' => 'Investigation Closed',
            'preventive_measures' => $request->preventive_measures
                ?? $incident->preventive_measures,
        ]);

        return response()->json([
            'status' => 200,
            'message' => 'Incident closed successfully.',
            // 'data' => new IncidentResource(
            //     $incident->fresh([
            //         'shift',
            //         'incidentType',
            //         'location',
            //         'person',
            //         'equipment',
            //         'equipmentName',
            //         'media'
            //     ])
            // )
        ]);
    }
    public function store(
        StoreIncidentRequest $request
    ) {


        DB::beginTransaction();


        try {


            $count = Incident::count() + 1;


            $incidentNo =
                'INC-' . date('Y') . '-' .
                str_pad($count, 5, '0', STR_PAD_LEFT);



            $incident = Incident::create([


                'incident_no' => $incidentNo,


                'incident_date' => \Carbon\Carbon::createFromFormat(
                    'Y-m-d H:i:s',
                    $request->incident_date
                )->format('Y-m-d H:i:s'),


                'shift_id' => $request->shift_id,

                'shift_plan_id' => $request->shift_plan_id,

                'incident_type_id' => $request->incident_type_id,


                'severity' => $request->severity,




                'location_id' => $request->location_id,

                'equipment_id' => $request->equipment_id,

                'equipment_name_id' => $request->equipment_name_id,
                'person_involved_id' => $request->person_involved_id,


                'incident_description' => $request->incident_description,


                'action_taken' => $request->action_taken,


                'preventive_measures' => $request->preventive_measures


            ]);


            ///// dd($request->file('media'));


            if ($request->hasFile('media')) {


                foreach ($request->file('media') as $file) {


                    $path = $file->store(
                        'incident_media',
                        'public'
                    );



                    $incident->media()->create([

                        'file_path' => $path,

                        'file_type' => $file->extension()

                    ]);
                }
            }



            DB::commit();



            return response()->json([


                'status' => 200,

                'message' => 'Incident logged successfully.',


                'incident_no' => $incidentNo,




                'severity' => $incident->severity,




            ], 200);
        } catch (\Throwable $e) {


            DB::rollBack();


            return response()->json([

                'status' => 500,

                'message' => $e->getMessage()

            ]);
        }
    }

    public function update(
        UpdateIncidentRequest $request,
        $id
    ) {

        DB::beginTransaction();

        try {

            $incident = Incident::find($id);

            if (!$incident) {

                return response()->json([

                    'status' => 404,

                    'message' => 'Incident not found'

                ]);
            }

            $incident->update([

                'incident_date' => \Carbon\Carbon::createFromFormat(
                    'Y-m-d H:i:s',
                    $request->incident_date
                )->format('Y-m-d H:i:s'),

                'shift_id' =>
                $request->shift_id,

                'shift_plan_id' =>
                $request->shift_plan_id,

                'incident_type_id' =>
                $request->incident_type_id,

                'severity' =>
                $request->severity,



                'location_id' =>
                $request->location_id,
                'equipment_id' => $request->equipment_id,

                'equipment_name_id' => $request->equipment_name_id,
                'person_involved_id' =>
                $request->person_involved_id,

                'incident_description' =>
                $request->incident_description,

                'action_taken' =>
                $request->action_taken,

                'preventive_measures' =>
                $request->preventive_measures

            ]);

            // Upload New Media
            if ($request->hasFile('media')) {

                foreach ($request->file('media') as $file) {

                    $path = $file->store(
                        'incident_media',
                        'public'
                    );

                    $incident->media()->create([

                        'file_path' => $path,

                        'file_type' =>
                        $file->extension()

                    ]);
                }
            }

            DB::commit();

            return response()->json([

                'status' => 200,

                'message' =>
                'Incident updated successfully'

            ]);
        } catch (\Throwable $th) {

            DB::rollBack();

            return response()->json([

                'status' => 500,

                'message' => $th->getMessage()

            ]);
        }
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xls,xlsx,csv|max:5120',
        ]);

        try {
            $import = new \App\Imports\IncidentImport();
            \Maatwebsite\Excel\Facades\Excel::import($import, $request->file('file'));

            $errors = $import->getErrors();
            $successCount = $import->getSuccessCount();

            if (count($errors) > 0) {
                return response()->json([
                    'status' => 422,
                    'message' => "Import completed with " . count($errors) . " errors. {$successCount} records imported successfully.",
                    'errors' => $errors
                ], 422);
            }

            return response()->json([
                'status' => 200,
                'message' => "Successfully imported {$successCount} incident records."
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to import incidents.',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
