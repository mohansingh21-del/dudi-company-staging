<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Http\Requests\StoreIncidentRequest;
use App\Http\Requests\UpdateIncidentRequest;
use App\Http\Resources\IncidentResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IncidentController extends Controller
{
    public function index(Request $request)
    {
        try {

            $limit = $request->input('limit', 10);

            $incidents = Incident::with([
                'shift',
                'incidentType',
                'location'
            ]);

            // Search
            if ($request->filled('search')) {

                $search = $request->search;

                $incidents->where(function ($query) use ($search) {

                    $query->where('incident_no', 'LIKE', "%{$search}%")
                        ->orWhere('incident_description', 'LIKE', "%{$search}%")

                        ->orWhereHas('incidentType', function ($q) use ($search) {

                            $q->where(
                                'incident_type',
                                'LIKE',
                                "%{$search}%"
                            );
                        })

                        ->orWhereHas('location', function ($q) use ($search) {

                            $q->where(
                                'name',
                                'LIKE',
                                "%{$search}%"
                            );
                        });
                });
            }

            // Date From
            if ($request->filled('date_from')) {

                $incidents->whereDate(
                    'incident_date',
                    '>=',
                    $request->date_from
                );
            }

            // Date To
            if ($request->filled('date_to')) {

                $incidents->whereDate(
                    'incident_date',
                    '<=',
                    $request->date_to
                );
            }

            // Shift Filter
            if ($request->filled('shift_id')) {

                $incidents->where(
                    'shift_id',
                    $request->shift_id
                );
            }

            // Location Filter
            if ($request->filled('location_id')) {

                $incidents->where(
                    'location_id',
                    $request->location_id
                );
            }

            // Incident Type Filter
            if ($request->filled('incident_type_id')) {

                $incidents->where(
                    'incident_type_id',
                    $request->incident_type_id
                );
            }

            $incidents = $incidents
                ->latest()
                ->paginate($limit);

            return response()->json([

                'status' => 200,

                'message' =>
                'Incident list fetched successfully',

                'data' =>
                IncidentResource::collection($incidents),

                'pagination' => [

                    'current_page' =>
                    $incidents->currentPage(),

                    'last_page' =>
                    $incidents->lastPage(),

                    'per_page' =>
                    $incidents->perPage(),

                    'total' =>
                    $incidents->total(),

                    'from' =>
                    $incidents->firstItem(),

                    'to' =>
                    $incidents->lastItem()

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


                'incident_date' => $request->incident_date,


                'shift_id' => $request->shift_id,


                'incident_type_id' => $request->incident_type_id,


                'severity' => $request->severity,


                'status' => $request->status,


                'location_id' => $request->location_id,


                'person_involved_id' => $request->person_involved_id,


                'incident_description' => $request->incident_description,


                'action_taken' => $request->action_taken,


                'preventive_measures' => $request->preventive_measures


            ]);





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


                'success' => true,


                'incident_no' => $incidentNo,


                'status' => $incident->status,


                'severity' => $incident->severity,


                'message' => 'Incident logged successfully.'


            ], 201);
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

                'incident_date' =>
                $request->incident_date,

                'shift_id' =>
                $request->shift_id,

                'incident_type_id' =>
                $request->incident_type_id,

                'severity' =>
                $request->severity,

                'status' =>
                $request->status,

                'location_id' =>
                $request->location_id,

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
}
